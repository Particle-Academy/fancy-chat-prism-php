<?php

declare(strict_types=1);

namespace FancyChat\Prism;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Prism\Graph\Chunk;
use Prism\Graph\Fact;
use Prism\Graph\Hit;
use Prism\Graph\ScopedGraph;
use Throwable;

/**
 * OPTIONAL: serves fancy-chat-ui's `KnowledgeAdapter` (the `./knowledge` explorer)
 * from prism-graph, so a host does not hand-map it.
 *
 * - **Scope is the host's.** `scope(Request): ?ScopedGraph` builds the graph view
 *   from the host's own authentication; null answers 403. Nothing the client sends
 *   names, widens or narrows a scope.
 * - **Every answer is a g282.1 listing** (`{contractVersion, items, nextCursor}`):
 *   prism-graph 0.3 stamps its own listings; the rest are stamped here.
 * - **Search hits and chunks by id are hydrated** into full chunk items (uri, sha256,
 *   writer, recordedAt, position) through `ScopedGraph::listChunks`, the store's own
 *   listing, not a parallel copy.
 * - **Accept and reject are off** unless the host passes `decider`, a resolver of the
 *   HUMAN actor from its authentication. Anything but `{kind: human, id}` is 403, and
 *   nothing in the request body is read. `onDecision` lets the host audit it.
 */
final class KnowledgeBridge
{
    public const string CONTRACT_VERSION = 'g282.1';

    /**
     * @param  Closure(Request): ?ScopedGraph  $scope
     * @param  (Closure(Request): ?array)|null  $decider  the human actor, or null to refuse
     * @param  (Closure(string, string, array): void)|null  $onDecision  (factId, accept|reject, actor)
     */
    public function __construct(
        private Closure $scope,
        private ?Closure $decider = null,
        private ?Closure $onDecision = null,
        private int $searchMax = 50,
        private int $hydratePages = 20,
        /** Chunk ids per `chunks` request (prism-graph's own ceiling is ScopedGraph::MAX_GET_IDS). */
        private int $maxIds = 200,
    ) {}

    /** Mounts the routes under `$prefix` (default `fancy-chat/knowledge`) with the host's middleware. */
    public static function routes(self $bridge, string $prefix = 'fancy-chat/knowledge', array $middleware = []): void
    {
        Route::prefix($prefix)->middleware($middleware)->group(function () use ($bridge): void {
            Route::get('search', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->search($g, (string) $r->query('q', ''), (int) $r->query('limit', 10))));
            Route::get('sources', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $g->listSources(self::cursor($r), self::limit($r))));
            Route::get('sources/{source}/chunks', fn (Request $r, string $source) => $bridge->respond($r, fn (ScopedGraph $g) => $g->listChunks($source, self::cursor($r), self::limit($r))));
            Route::get('chunks', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->chunks($g, self::ids($r, $bridge->maxIds))));
            Route::get('facts', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->facts($g, self::subject($r), $r->boolean('unaccepted'))));
            Route::get('facts/timeline', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->timeline($g, self::subject($r))));
            // Shipped by prism-graph later (G-282 C, and the g282.2 fact listing): served
            // as-is once ScopedGraph has the method, 501 until then.
            Route::get('facts/all', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->later($g, 'listFacts', [self::optionalSubject($r), self::cursor($r), self::limit($r)])));
            Route::get('ingest/jobs', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->later($g, 'ingestJobs', [self::cursor($r), self::limit($r)])));
            Route::get('ingest/worker', fn (Request $r) => $bridge->respond($r, fn (ScopedGraph $g) => $bridge->later($g, 'workerStatus', [])));
            if ($bridge->decider !== null) {
                Route::post('facts/{fact}/accept', fn (Request $r, string $fact) => $bridge->decide($r, $fact, 'accept'));
                Route::post('facts/{fact}/reject', fn (Request $r, string $fact) => $bridge->decide($r, $fact, 'reject'));
            }
        });
    }

    /** @return array{contractVersion: string, items: list<array<string, mixed>>, nextCursor: null} */
    public function search(ScopedGraph $graph, string $query, int $limit): array
    {
        if ($limit < 1 || $limit > $this->searchMax) {
            throw new InvalidArgumentException("limit must be between 1 and {$this->searchMax}.");
        }
        $hits = trim($query) === '' ? [] : $graph->search($query, $limit);
        $items = $this->hydrate($graph, array_map(fn (Hit $h): Chunk => $h->chunk, $hits));
        foreach ($hits as $i => $hit) {
            $items[$i]['score'] = $hit->score;
            $items[$i]['method'] = $hit->method;
        }

        return self::listing($items);
    }

    /**
     * @param  list<string>  $ids
     * @return array{contractVersion: string, items: list<array<string, mixed>>, nextCursor: null}
     */
    public function chunks(ScopedGraph $graph, array $ids): array
    {
        return self::listing($this->hydrate($graph, $graph->get($ids)));
    }

    /** @return array{contractVersion: string, items: list<array<string, mixed>>, nextCursor: null} */
    public function facts(ScopedGraph $graph, string $subject, bool $unaccepted): array
    {
        return self::listing(array_map(self::fact(...), $graph->facts($subject, unaccepted: $unaccepted)));
    }

    /** @return array{contractVersion: string, items: list<array<string, mixed>>, nextCursor: null} */
    public function timeline(ScopedGraph $graph, string $subject): array
    {
        return self::listing(array_map(self::fact(...), $graph->timeline($subject)));
    }

    /**
     * Chunk items with full provenance, in the order given. Each source's live chunks
     * are read through its own listing, a page at a time, until every wanted chunk is
     * found (at most `hydratePages` pages per source). A chunk the listing does not
     * hold (retired since it was cited) keeps what the chunk itself carries and is
     * marked `retired: true`; its provenance strings are empty, never invented.
     *
     * @param  list<Chunk>  $chunks
     * @return list<array<string, mixed>>
     */
    private function hydrate(ScopedGraph $graph, array $chunks): array
    {
        $wanted = [];
        foreach ($chunks as $c) {
            $wanted[$c->sourceId][$c->id] = true;
        }
        $found = [];
        foreach ($wanted as $sourceId => $ids) {
            $cursor = null;
            for ($page = 0; $page < $this->hydratePages && $ids !== []; $page++) {
                $listing = $graph->listChunks((string) $sourceId, $cursor);
                foreach ($listing['items'] as $item) {
                    if (isset($ids[$item['chunkId']])) {
                        $found[$item['chunkId']] = $item;
                        unset($ids[$item['chunkId']]);
                    }
                }
                $cursor = $listing['nextCursor'];
                if ($cursor === null) {
                    break;
                }
            }
        }

        return array_map(fn (Chunk $c): array => $found[$c->id] ?? [
            'chunkId' => $c->id,
            'sourceId' => $c->sourceId,
            'text' => $c->text,
            'position' => 0,
            'retired' => true,
            'provenance' => [
                'uri' => '', 'sha256' => '', 'byteStart' => $c->byteStart, 'byteEnd' => $c->byteEnd,
                'lineStart' => $c->lineStart, 'lineEnd' => $c->lineEnd, 'headingPath' => $c->headingPath,
                'recipe' => $c->recipe, 'trust' => $c->trust?->value ?? '', 'writer' => null, 'recordedAt' => '',
            ],
        ], $chunks);
    }

    /** @return array<string, mixed> prism-graph's Fact, as the g282.1 item fancy-chat-ui reads */
    private static function fact(Fact $f): array
    {
        return [
            'id' => $f->id,
            'subject' => $f->subject,
            'predicate' => $f->predicate,
            'object' => $f->object,
            'evidence' => array_values($f->evidence),
            'validFrom' => $f->validFrom,
            'validTo' => $f->validTo,
            'supersededBy' => $f->supersededBy,
            'status' => $f->status?->value,
            'recordedAt' => $f->recordedAt,
            'expiredAt' => $f->expiredAt,
        ];
    }

    /**
     * A ScopedGraph method prism-graph has not shipped yet, passed through when it
     * exists. Its own contractVersion travels with it; nothing is stamped or guessed.
     *
     * @param  list<mixed>  $args
     */
    private function later(ScopedGraph $graph, string $method, array $args): array
    {
        if (! method_exists($graph, $method)) {
            throw new UnsupportedKnowledgeCall;
        }

        return $graph->{$method}(...$args);
    }

    /** @param  list<array<string, mixed>>  $items */
    private static function listing(array $items): array
    {
        return ['contractVersion' => self::CONTRACT_VERSION, 'items' => array_values($items), 'nextCursor' => null];
    }

    /** @param  Closure(ScopedGraph): array  $read */
    private function respond(Request $request, Closure $read): JsonResponse
    {
        $graph = ($this->scope)($request);
        if (! $graph instanceof ScopedGraph) {
            return new JsonResponse(['error' => 'knowledge.scope'], 403);
        }
        try {
            return new JsonResponse($read($graph));
        } catch (UnsupportedKnowledgeCall) {
            return new JsonResponse(['error' => 'knowledge.unsupported'], 501);
        } catch (InvalidArgumentException) {
            // A bad cursor, limit, id list or subject. The message is not echoed: it may quote input.
            return new JsonResponse(['error' => 'knowledge.request'], 422);
        }
    }

    private function decide(Request $request, string $factId, string $decision): JsonResponse
    {
        $actor = ($this->decider)($request);
        if (! is_array($actor) || ($actor['kind'] ?? null) !== 'human' || ! is_string($actor['id'] ?? null) || $actor['id'] === '') {
            return new JsonResponse(['error' => 'knowledge.actor.human'], 403);
        }
        $graph = ($this->scope)($request);
        if (! $graph instanceof ScopedGraph) {
            return new JsonResponse(['error' => 'knowledge.scope'], 403);
        }
        try {
            $decision === 'accept' ? $graph->accept($factId) : $graph->retract($factId);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'knowledge.fact'], 422);
        }
        $actor = ['kind' => 'human', 'id' => $actor['id']];
        if ($this->onDecision !== null) {
            ($this->onDecision)($factId, $decision, $actor);
        }

        return new JsonResponse(['ok' => true, 'factId' => $factId, 'decision' => $decision]);
    }

    private static function cursor(Request $r): ?string
    {
        $c = $r->query('cursor');

        return is_string($c) && $c !== '' ? $c : null;
    }

    private static function limit(Request $r): ?int
    {
        $l = $r->query('limit');

        return is_numeric($l) ? (int) $l : null;
    }

    /** @return list<string> */
    private static function ids(Request $r, int $max): array
    {
        $ids = $r->query('ids');
        if (! is_array($ids) || $ids === [] || ! array_is_list($ids) || count(array_filter($ids, 'is_string')) !== count($ids)) {
            throw new InvalidArgumentException('ids');
        }
        if (count($ids) > min($max, ScopedGraph::MAX_GET_IDS)) {
            throw new InvalidArgumentException('ids');
        }

        return $ids;
    }

    private static function optionalSubject(Request $r): ?string
    {
        $s = $r->query('subject');

        return is_string($s) && trim($s) !== '' ? $s : null;
    }

    private static function subject(Request $r): string
    {
        $s = $r->query('subject');
        if (! is_string($s) || trim($s) === '') {
            throw new InvalidArgumentException('subject');
        }

        return $s;
    }
}
