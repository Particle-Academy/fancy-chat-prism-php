<?php

declare(strict_types=1);

use FancyChat\FancyChat;
use FancyChat\Prism\HarnessBackend;
use FancyChat\Prism\Tests\Support\FakeSession;
use FancyChat\Prism\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Subagents\RunContext;

uses()->beforeEach(function (): void {
    TestApp::boot();
})->afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__.'/Feature');

function chat(): FancyChat
{
    return app(FancyChat::class);
}

/** A host whose `X-Who: kind:id` header names the actor; access allows all. */
function host(HarnessBackend $backend): FancyChat
{
    return chat()
        ->actor(function (Request $request): ?array {
            $who = $request->header('X-Who');
            if (! is_string($who) || ! str_contains($who, ':')) {
                return null;
            }
            [$kind, $id] = explode(':', $who, 2);

            return ['kind' => $kind, 'id' => $id];
        })
        ->access(fn (): bool => true)
        ->backend($backend);
}

/**
 * @param  array<string, mixed>|null  $body
 * @return array{status: int, body: mixed}
 */
function http(string $method, string $uri, ?array $body = null, ?string $who = 'human:u_1'): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($who !== null) {
        $server['HTTP_X_WHO'] = $who;
    }
    $request = Request::create($uri, $method, [], [], [], $server, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
}

/** A human sends one text message on thread t_1; returns the turn id. */
function say(string $text, string $thread = 't_1'): string
{
    $r = http('POST', "/fancy-chat/threads/{$thread}/messages", ['parts' => [['id' => 'p1', 'kind' => 'text', 'format' => 'plain', 'text' => $text]]]);
    expect($r['status'])->toBeLessThan(300);

    return lastTurn($thread)['turnId'];
}

/** @return array<string, mixed> */
function lastTurn(string $thread = 't_1'): array
{
    $turns = chat()->snapshot($thread)['turns'];

    return $turns[count($turns) - 1];
}

/**
 * Every logged event of a thread, raw.
 *
 * @return list<array<string, mixed>>
 */
function events(string $thread = 't_1'): array
{
    return chat()->store()->after($thread, 0, 10000);
}

/**
 * The logged events of the given types, in order.
 *
 * @param  list<string>  $types
 * @return list<array<string, mixed>>
 */
function only(array $types, string $thread = 't_1'): array
{
    return array_values(array_filter(events($thread), fn (array $e): bool => in_array($e['type'], $types, true)));
}

/** @return list<string> the logged event types, in order, minus the server's own bookkeeping */
function backendTypes(string $thread = 't_1'): array
{
    $own = ['message.created', 'turn.started', 'turn.transition', 'delivery.changed'];
    $types = [];
    foreach (events($thread) as $e) {
        if (($e['actor']['kind'] ?? null) === 'agent' || ! in_array($e['type'], $own, true)) {
            $types[] = $e['type'];
        }
    }

    return $types;
}

/**
 * Real RunSteps, numbered from `$from` with no gaps, on one run.
 *
 * @param  list<array{0: string, 1?: array<string, mixed>}>  $script  [type, data]
 * @return list<RunStep>
 */
function runSteps(?RunContext $context, array $script, string $runId = 'run_1', ?string $parentRunId = null, ?string $rootRunId = null, int $from = 1): array
{
    $out = [];
    foreach ($script as $i => $entry) {
        $out[] = new RunStep($runId, $parentRunId, $rootRunId ?? $runId, $context?->turnId, $from + $i, '2026-11-15T10:00:00.'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).'Z', $entry[0], $entry[1] ?? []);
    }

    return $out;
}

function backendFor(FakeSession $session, array $options = []): HarnessBackend
{
    return new HarnessBackend(fn (string $threadId): FakeSession => $session, ...$options);
}
