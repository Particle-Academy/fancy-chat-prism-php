<?php

declare(strict_types=1);

use FancyChat\Prism\KnowledgeBridge;
use Illuminate\Http\Request;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Fact;
use Prism\Graph\Graph;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Trust;

/*
 * The optional knowledge bridge: routes that serve fancy-chat-ui's KnowledgeAdapter
 * from a ScopedGraph the HOST binds per request. The client never names a scope.
 */

function graphWithData(): Graph
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (BaseSchema::statements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $graph = new Graph(new PdoGraphStore($pdo));
    $mine = $graph->scope(new Scope('acme', 'u_1'));
    $mine->ingest('handbook', 'file:///handbook.md', "# Refunds\n\nRefunds take 30 days.\n\n# Shipping\n\nShipping is free over 50.\n", new MarkdownChunker, new Origin(Trust::Document, 'ingest'), ['title' => 'Handbook', 'kind' => 'md']);
    $mine->ingest('notes', 'file:///notes.md', "# Notes\n\nThe agent thinks Acme is optimistic.\n", new MarkdownChunker, new Origin(Trust::Agent, 'agent'));
    $sourceOf = fn (string $title): string => collect($mine->listSources()['items'])->firstWhere('title', $title)['sourceId'];
    $chunks = $mine->listChunks($sourceOf('Handbook'))['items'];
    $refund = collect($chunks)->first(fn (array $c): bool => str_contains($c['text'], 'Refunds take'))['chunkId'];
    $mine->assert(new Fact('f_refund', 'acme', 'refund_window', '30 days', [$refund], '2026-01-01T00:00:00Z'));
    $notes = $mine->listChunks($sourceOf('file:///notes.md'))['items'][0]['chunkId'];
    $mine->assert(new Fact('f_mood', 'acme', 'mood', 'optimistic', [$notes], '2026-01-01T00:00:00Z'));
    // Another owner's data, which u_1 must never see.
    $graph->scope(new Scope('acme', 'u_2'))->ingest('secret', 'file:///secret.md', "# Secret\n\nRefunds take 99 days for u_2.\n", new MarkdownChunker, new Origin(Trust::Document, 'ingest'));

    return $graph;
}

/** The host binds the scope from ITS authentication (here X-Who), never from the request's data. */
function knowledgeHost(Graph $graph, array $options = []): KnowledgeBridge
{
    $bridge = new KnowledgeBridge(...['scope' => function (Request $r) use ($graph): ?ScopedGraph {
        $who = $r->header('X-Who');

        return is_string($who) && str_contains($who, ':') ? $graph->scope(new Scope('acme', explode(':', $who, 2)[1])) : null;
    }] + $options);
    KnowledgeBridge::routes($bridge);
    app('router')->getRoutes()->refreshNameLookups();

    return $bridge;
}

function kget(string $uri, ?string $who = 'human:u_1'): array
{
    return http('GET', '/fancy-chat/knowledge'.$uri, null, $who);
}

it('lists sources and chunks as g282.1, scoped by the host, with a cursor', function (): void {
    knowledgeHost(graphWithData());
    $sources = kget('/sources?limit=1');
    expect($sources['status'])->toBe(200)
        ->and($sources['body']['contractVersion'])->toBe('g282.1')
        ->and($sources['body']['items'])->toHaveCount(1)
        ->and($sources['body']['nextCursor'])->toBeString();
    $next = kget('/sources?limit=1&cursor='.urlencode($sources['body']['nextCursor']));
    $all = [...$sources['body']['items'], ...$next['body']['items']];
    expect(array_column($all, 'title'))->toEqualCanonicalizing(['Handbook', 'file:///notes.md']);
    $handbook = collect($all)->firstWhere('title', 'Handbook')['sourceId'];
    $chunks = kget('/sources/'.rawurlencode($handbook).'/chunks');
    expect($chunks['body']['contractVersion'])->toBe('g282.1')
        ->and($chunks['body']['items'][0]['provenance']['uri'])->toBe('file:///handbook.md');
});

it('never shows another owner\'s data, and never takes a scope from the client', function (): void {
    knowledgeHost(graphWithData());
    $hits = kget('/search?q=refunds&scope[owner]=u_2&owner=u_2');
    expect(json_encode($hits['body']))->not->toContain('99 days')->toContain('30 days');
    expect(kget('/sources', null)['status'])->toBe(403);
});

it('hydrates search hits into full chunk items with provenance', function (): void {
    knowledgeHost(graphWithData());
    $r = kget('/search?q=refunds&limit=5');
    expect($r['status'])->toBe(200)->and($r['body']['contractVersion'])->toBe('g282.1')->and($r['body']['nextCursor'])->toBeNull();
    $hit = $r['body']['items'][0];
    expect($hit['text'])->toContain('Refunds take 30 days')
        ->and($hit['provenance'])->toMatchArray(['uri' => 'file:///handbook.md', 'writer' => 'ingest', 'trust' => 'document'])
        ->and($hit['provenance']['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($hit['provenance']['recordedAt'])->toBeString()->not->toBe('')
        ->and($hit['position'])->toBeInt();
});

it('hydrates chunks by id (evidence passages)', function (): void {
    $graph = graphWithData();
    knowledgeHost($graph);
    $ref = $graph->scope(new Scope('acme', 'u_1'))->facts('acme')[0]->evidence[0];
    $r = kget('/chunks?ids[]='.urlencode($ref));
    expect($r['body']['contractVersion'])->toBe('g282.1')
        ->and($r['body']['items'])->toHaveCount(1)
        ->and($r['body']['items'][0]['chunkId'])->toBe($ref)
        ->and($r['body']['items'][0]['provenance']['uri'])->toBe('file:///handbook.md');
});

it('serves facts and the timeline as g282.1 listings with string statuses', function (): void {
    knowledgeHost(graphWithData());
    $facts = kget('/facts?subject=acme&unaccepted=1');
    expect($facts['body']['contractVersion'])->toBe('g282.1');
    $byId = collect($facts['body']['items'])->keyBy('id');
    expect($byId['f_refund'])->toMatchArray(['subject' => 'acme', 'predicate' => 'refund_window', 'object' => '30 days', 'status' => 'accepted'])
        ->and($byId['f_mood']['status'])->toBe('belief')
        ->and(array_keys($byId['f_refund']))->toEqualCanonicalizing(['id', 'subject', 'predicate', 'object', 'evidence', 'validFrom', 'validTo', 'supersededBy', 'status', 'recordedAt', 'expiredAt']);
    expect(collect(kget('/facts?subject=acme')['body']['items'])->pluck('id')->all())->toBe(['f_refund']);
    $timeline = kget('/facts/timeline?subject=acme');
    expect($timeline['body']['contractVersion'])->toBe('g282.1')->and(count($timeline['body']['items']))->toBeGreaterThanOrEqual(2);
});

it('refuses a bad request plainly: no subject, a tampered cursor, too many ids', function (): void {
    knowledgeHost(graphWithData());
    expect(kget('/facts')['status'])->toBe(422)
        ->and(kget('/facts?subject=%20')['status'])->toBe(422)
        ->and(kget('/sources?cursor=garbage')['status'])->toBe(422)
        ->and(kget('/chunks?'.http_build_query(['ids' => array_map(fn ($i) => "c{$i}", range(1, 201))]))['status'])->toBe(422);
});

it('accept and reject are off by default: no route', function (): void {
    knowledgeHost(graphWithData());
    expect(fn () => http('POST', '/fancy-chat/knowledge/facts/f_mood/accept', [], 'human:u_1'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('accept and reject, when enabled, take a HUMAN actor from the host resolver only', function (): void {
    $graph = graphWithData();
    $mine = $graph->scope(new Scope('acme', 'u_1'));
    // A proposal: evidence the policy does not trust alone.
    $mine->ingest('web', 'https://example.com/a', "# Web\n\nAcme moved to Lisbon.\n", new MarkdownChunker, new Origin(Trust::Tool, 'search'));
    $web = collect($mine->listSources()['items'])->firstWhere('title', 'https://example.com/a')['sourceId'];
    $webChunk = $mine->listChunks($web)['items'][0]['chunkId'];
    $mine->assert(new Fact('f_city', 'acme', 'city', 'Lisbon', [$webChunk], '2026-01-01T00:00:00Z'));
    expect(collect($mine->facts('acme', unaccepted: true))->firstWhere('id', 'f_city')->status->value)->toBe('proposed');

    $decisions = [];
    knowledgeHost($graph, ['decider' => function (Request $r) use (&$decisions): ?array {
        $who = $r->header('X-Who');
        if (! is_string($who) || ! str_contains($who, ':')) {
            return null;
        }
        [$kind, $id] = explode(':', $who, 2);

        return ['kind' => $kind, 'id' => $id];
    }, 'onDecision' => function (string $factId, string $decision, array $actor) use (&$decisions): void {
        $decisions[] = [$factId, $decision, $actor];
    }]);

    expect(http('POST', '/fancy-chat/knowledge/facts/f_city/accept', ['actor' => ['kind' => 'human', 'id' => 'u_1']], 'agent:u_1')['status'])->toBe(403);
    expect(http('POST', '/fancy-chat/knowledge/facts/f_city/accept', [], null)['status'])->toBe(403);
    expect(collect($mine->facts('acme', unaccepted: true))->firstWhere('id', 'f_city')->status->value)->toBe('proposed');

    expect(http('POST', '/fancy-chat/knowledge/facts/f_city/accept', [], 'human:u_1')['status'])->toBe(200);
    expect(collect($mine->facts('acme'))->firstWhere('id', 'f_city')?->status->value)->toBe('accepted')
        ->and($decisions)->toBe([['f_city', 'accept', ['kind' => 'human', 'id' => 'u_1']]]);

    expect(http('POST', '/fancy-chat/knowledge/facts/f_mood/reject', [], 'human:u_1')['status'])->toBe(200);
    expect(collect($mine->facts('acme', unaccepted: true))->firstWhere('id', 'f_mood'))->toBeNull();
});

it('serves what prism-graph has not shipped yet as 501, never a guess: listFacts, ingest jobs, worker status', function (): void {
    knowledgeHost(graphWithData());
    foreach (['/facts/all', '/ingest/jobs', '/ingest/worker'] as $uri) {
        $r = kget($uri);
        expect($r['status'])->toBe(501)->and($r['body'])->toBe(['error' => 'knowledge.unsupported']);
    }
    expect(kget('/facts/all', null)['status'])->toBe(403);
});

it('hydrates a chunk past the first listing page of its source', function (): void {
    $graph = graphWithData();
    $mine = $graph->scope(new Scope('acme', 'u_1'));
    $doc = implode("\n\n", array_map(fn (int $i): string => "# Part {$i}\n\nSection {$i} body text.", range(1, 70)));
    $mine->ingest('long', 'file:///long.md', $doc, new MarkdownChunker, new Origin(Trust::Document, 'ingest'), ['title' => 'Long']);
    $long = collect($mine->listSources()['items'])->firstWhere('title', 'Long')['sourceId'];
    $page = $mine->listChunks($long);
    expect($page['nextCursor'])->toBeString();
    $last = $mine->listChunks($long, $page['nextCursor'])['items'];
    $id = end($last)['chunkId'];
    knowledgeHost($graph);
    $r = kget('/chunks?ids[]='.urlencode($id));
    expect($r['body']['items'][0])->not->toHaveKey('retired')
        ->and($r['body']['items'][0]['provenance']['uri'])->toBe('file:///long.md');
});
