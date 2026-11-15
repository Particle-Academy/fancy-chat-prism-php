<?php

declare(strict_types=1);

use FancyChat\Prism\Tests\Support\FakeSession;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Subagents\RunContext;

it('maps each thinking block to started, delta and completed, in order, several per run', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['step_start'],
        ['thinking', ['delta' => 'First ']],
        ['thinking', ['delta' => 'idea.']],
        ['text', ['delta' => 'Hello']],
        ['thinking', ['delta' => 'Second idea.']],
        ['step_finish', ['text' => 'Hello']],
    ]))));
    say('hi');

    $thinking = only(['thinking.started', 'thinking.delta', 'thinking.completed']);
    expect(array_column($thinking, 'type'))->toBe([
        'thinking.started', 'thinking.delta', 'thinking.delta', 'thinking.completed',
        'thinking.started', 'thinking.delta', 'thinking.completed',
    ])->and(array_map(fn (array $e): ?string => $e['data']['text'] ?? null, $thinking))->toBe([null, 'First ', 'idea.', null, null, 'Second idea.', null])
        ->and(array_column($thinking, 'runId'))->each->toBe('run_1');

    // The first block is closed before the text starts.
    $types = backendTypes();
    expect(array_search('thinking.completed', $types, true))->toBeLessThan(array_search('part.started', $types, true));
});

it('maps text deltas onto one assistant message part, closed at the step boundary', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['step_start'],
        ['text', ['delta' => 'Acme ']],
        ['text', ['delta' => 'is won.']],
        ['step_finish', ['text' => 'Acme is won.']],
    ]))));
    say('status?');

    $assistant = array_values(array_filter(chat()->snapshot('t_1')['messages'], fn (array $m): bool => $m['role'] === 'assistant'));
    expect($assistant)->toHaveCount(1)
        ->and($assistant[0]['parts'])->toHaveCount(1)
        ->and($assistant[0]['parts'][0]['kind'])->toBe('text')
        ->and($assistant[0]['parts'][0]['format'])->toBe('markdown')
        ->and($assistant[0]['parts'][0]['text'])->toBe('Acme is won.')
        ->and($assistant[0]['open'])->toBe([])
        ->and(array_column(only(['part.delta']), 'data'))->sequence(
            fn ($d) => $d->text->toBe('Acme '),
            fn ($d) => $d->text->toBe('is won.'),
        );
    expect(lastTurn()['state'])->toBe('settled')->and(lastTurn()['outcome'])->toBe('completed');
});

it('starts a new message for text that follows a tool, so it renders after the tool', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['step_start'],
        ['text', ['delta' => 'Looking.']],
        ['tool_call', ['toolCallId' => 'tc_1', 'name' => 'search', 'args' => (object) ['q' => 'acme'], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
        ['tool_result', ['toolCallId' => 'tc_1', 'ok' => true, 'result' => '3 hits', 'refused' => false, 'durationMs' => 40, 'durationExact' => true]],
        ['step_finish', ['text' => 'Looking.']],
        ['step_start'],
        ['text', ['delta' => 'Found it.']],
        ['step_finish', ['text' => 'Found it.']],
    ]))));
    say('find acme');

    $order = array_map(fn (array $r): string => $r['kind'], chat()->snapshot('t_1')['order']);
    expect($order)->toBe(['message', 'message', 'tool', 'message']);
    $assistant = array_values(array_filter(chat()->snapshot('t_1')['messages'], fn (array $m): bool => $m['role'] === 'assistant'));
    expect(array_map(fn (array $m): string => $m['parts'][0]['text'], $assistant))->toBe(['Looking.', 'Found it.']);
});

it('maps tool_call to tool.called with toolCallId, name, args and startedAt', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['tool_call', ['toolCallId' => 'tc_9', 'name' => 'lookup', 'args' => (object) ['id' => 7, 'deep' => (object) ['x' => true]], 'startedAt' => '2026-11-15T10:00:01.123456Z']],
    ]))));
    say('go');

    $called = only(['tool.called'])[0];
    expect($called['data']['toolCallId'])->toBe('tc_9')
        ->and($called['data']['name'])->toBe('lookup')
        ->and($called['data']['args'])->toBe(['id' => 7, 'deep' => ['x' => true]])
        ->and($called['data']['startedAt'])->toBe('2026-11-15T10:00:01.123456Z')
        ->and($called['runId'])->toBe('run_1');
});

it('maps tool_result ok, failure and refusal, with the duration and its exactness', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['tool_call', ['toolCallId' => 'a', 'name' => 'one', 'args' => (object) [], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
        ['tool_result', ['toolCallId' => 'a', 'ok' => true, 'result' => 'fine', 'refused' => false, 'durationMs' => 12, 'durationExact' => true]],
        ['tool_call', ['toolCallId' => 'b', 'name' => 'two', 'args' => (object) [], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
        ['tool_result', ['toolCallId' => 'b', 'ok' => false, 'error' => ['code' => 'tool_failed', 'message' => 'disk full'], 'refused' => false, 'durationMs' => 7, 'durationExact' => false]],
        ['tool_call', ['toolCallId' => 'c', 'name' => 'three', 'args' => (object) [], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
        ['tool_result', ['toolCallId' => 'c', 'ok' => false, 'error' => ['code' => 'approval_denied', 'message' => 'denied by a person'], 'refused' => true, 'durationMs' => 0, 'durationExact' => true]],
    ]))));
    say('go');

    $results = array_column(only(['tool.result']), 'data');
    expect(array_map(fn (array $d): array => [$d['toolCallId'], $d['outcome'], $d['durationMs'], $d['durationExact'], $d['output']], $results))->toBe([
        ['a', 'ok', 12, true, 'fine'],
        ['b', 'failure', 7, false, 'disk full'],
        ['c', 'refusal', 0, true, 'denied by a person'],
    ])->and($results[0])->not->toHaveKey('errorCode')
        ->and($results[1]['errorCode'])->toBe('tool_failed')
        ->and($results[2]['errorCode'])->toBe('approval_denied');

    $tools = chat()->snapshot('t_1')['tools'];
    expect(array_column($tools, 'outcome'))->toBe(['ok', 'failure', 'refusal'])
        ->and(array_column($tools, 'durationMs'))->toBe([12, 7, 0]);
});

it('never claims an exact duration Harness did not state', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['tool_call', ['toolCallId' => 'a', 'name' => 'one', 'args' => (object) [], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
        ['tool_result', ['toolCallId' => 'a', 'ok' => true, 'result' => 'fine', 'refused' => false, 'durationMs' => 12]],
    ]))));
    say('go');

    expect(only(['tool.result'])[0]['data']['durationExact'])->toBeFalse();
});

it('keeps Harness correlation: runId, parentRunId, rootRunId on the envelope and turnId and seq on every event', function (): void {
    $session = new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['thinking', ['delta' => 'hm']],
        ['text', ['delta' => 'ok']],
        ['text', ['delta' => '!']],
    ], 'run_child', 'run_parent', 'run_root', from: 5));
    host(backendFor($session));
    $turnId = say('go');

    // The turn travels to Harness as the RunContext's turnId.
    expect($session->streams[0]['context']?->turnId)->toBe($turnId)
        ->and($session->streams[0]['prompt'])->toBe('go');

    $mapped = array_values(array_filter(events(), fn (array $e): bool => isset($e['data']['harness'])));
    expect($mapped)->not->toBeEmpty();
    foreach ($mapped as $e) {
        expect($e['runId'])->toBe('run_child')
            ->and($e['parentRunId'])->toBe('run_parent')
            ->and($e['rootRunId'])->toBe('run_root')
            ->and($e['data']['harness']['turnId'])->toBe($turnId)
            ->and($e['data']['harness']['runId'])->toBe('run_child');
    }
    $delta = only(['thinking.delta'])[0];
    expect($delta['data']['harness']['seq'])->toBe(5)
        ->and($delta['data']['harness']['at'])->toBe('2026-11-15T10:00:00.000000Z');
    expect(only(['part.delta'])[0]['data']['harness']['seq'])->toBe(6);
    // A closing event carries the step that closed the block, so seq order is log order.
    expect(only(['thinking.completed'])[0]['data']['harness']['seq'])->toBe(6)
        ->and(only(['thinking.completed'])[0]['runId'])->toBe('run_child')
        ->and(only(['part.completed'])[0]['data']['harness']['seq'])->toBe(7);
});

it('maps Harness run events to run.*, keeping parentRunId and rootRunId, and ignores other sessions', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        event(new RunStarted('harness:fake', 'run_1', 'default', 'anthropic', 'claude', 'run_parent', 'run_root'));
        event(new RunStarted('harness:someone-else', 'run_x', 'default', 'anthropic', 'claude'));
        yield from runSteps($c, [['text', ['delta' => 'done']]], 'run_1', 'run_parent', 'run_root');
        event(new RunFinished('harness:fake', 'run_1', 'stop', 1, false, 'run_parent', 'run_root'));
    })));
    say('go');

    $runs = only(['run.started', 'run.finished', 'run.failed']);
    expect(array_column($runs, 'type'))->toBe(['run.started', 'run.finished'])
        ->and(array_column($runs, 'runId'))->toBe(['run_1', 'run_1'])
        ->and(array_column($runs, 'parentRunId'))->toBe(['run_parent', 'run_parent'])
        ->and(array_column($runs, 'rootRunId'))->toBe(['run_root', 'run_root'])
        ->and($runs[0]['data'])->toMatchArray(['mode' => 'default', 'provider' => 'anthropic', 'model' => 'claude'])
        ->and($runs[1]['data'])->toMatchArray(['finishReason' => 'stop', 'steps' => 1, 'awaitingApproval' => false]);
    // run.started precedes the first mapped step; run.finished follows the last.
    $types = backendTypes();
    expect(array_search('run.started', $types, true))->toBeLessThan(array_search('part.started', $types, true))
        ->and(array_search('run.finished', $types, true))->toBeGreaterThan(array_search('part.delta', $types, true));
});

it('a root run carries its own id as rootRunId', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        event(new RunStarted('harness:fake', 'run_1', 'default', 'anthropic', 'claude'));
        yield from runSteps($c, [['text', ['delta' => 'x']]]);
    })));
    say('go');

    $started = only(['run.started'])[0];
    expect($started['parentRunId'])->toBeNull()->and($started['rootRunId'])->toBe('run_1');
});

it('maps an error step to a failed turn, naming the code and never the provider message', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        yield from runSteps($c, [
            ['text', ['delta' => 'Partial']],
            ['error', ['error' => ['code' => 'overloaded', 'message' => 'The model stream reported an error.']]],
        ]);
        event(new RunFailed('harness:fake', 'run_1', 'error'));
    })));
    say('go');

    $turn = lastTurn();
    expect($turn['state'])->toBe('settled')
        ->and($turn['outcome'])->toBe('failed')
        ->and($turn['reason'])->toBe('harness.error: overloaded')
        // The run's own end is still recorded, and the open text part is closed.
        ->and(array_column(only(['run.failed']), 'data'))->toBe([['exception' => 'error']])
        ->and(only(['part.completed']))->toHaveCount(1);
});

it('fails the turn when Harness throws, after recording the run events it saw', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        event(new RunStarted('harness:fake', 'run_1', 'default', 'anthropic', 'claude'));
        yield from runSteps($c, [['text', ['delta' => 'a']]]);
        event(new RunFailed('harness:fake', 'run_1', RuntimeException::class));

        throw new RuntimeException('provider said: secret-url');
    })));
    say('go');

    expect(lastTurn()['outcome'])->toBe('failed')
        ->and(lastTurn()['reason'])->toBe('backend.error')
        ->and(array_column(only(['run.started', 'run.failed']), 'type'))->toBe(['run.started', 'run.failed'])
        ->and(json_encode(events()))->not->toContain('secret-url');
});

it('refuses an unknown step type rather than guessing at it', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['text', ['delta' => 'a']],
        ['hologram', ['beam' => 1]],
        ['text', ['delta' => 'never shown']],
    ]))));
    say('go');

    expect(lastTurn()['outcome'])->toBe('failed')
        ->and(lastTurn()['reason'])->toBe('harness.step-type: hologram')
        ->and(json_encode(events()))->not->toContain('never shown');
});

it('fails a turn with no text to prompt with, without starting a Harness run', function (): void {
    $session = new FakeSession(fn (): array => []);
    $events = iterator_to_array(backendFor($session)->start(new FancyChat\Backend\TurnInput('t_1', 'u_1', 'message', 'm_1', [['id' => 'c', 'kind' => 'code', 'language' => 'php', 'code' => 'x']])), false);

    expect($events)->toBe([['type' => 'turn.settled', 'data' => ['action' => 'fail', 'reason' => 'harness.empty-prompt']]])
        ->and($session->streams)->toBe([]);
});

it('joins every text part of the message into the prompt', function (): void {
    $session = new FakeSession(fn (): array => []);
    iterator_to_array(backendFor($session)->start(new FancyChat\Backend\TurnInput('t_1', 'u_1', 'message', 'm_1', [
        ['id' => 'a', 'kind' => 'text', 'format' => 'plain', 'text' => 'one'],
        ['id' => 'b', 'kind' => 'code', 'language' => 'php', 'code' => 'skip'],
        ['id' => 'c', 'kind' => 'text', 'format' => 'plain', 'text' => 'two'],
    ])), false);

    expect($session->streams[0]['prompt'])->toBe("one\n\ntwo");
});

it('a refusal is a refusal even if the step also says ok', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => runSteps($c, [
        ['tool_call', ['toolCallId' => 'a', 'name' => 'one', 'args' => (object) [], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
        ['tool_result', ['toolCallId' => 'a', 'ok' => true, 'result' => 'RAN', 'error' => ['code' => 'tool_call_refused', 'message' => 'not allowed'], 'refused' => true, 'durationMs' => 1, 'durationExact' => true]],
    ]))));
    say('go');

    expect(only(['tool.result'])[0]['data'])->toMatchArray(['outcome' => 'refusal', 'output' => 'not allowed', 'errorCode' => 'tool_call_refused']);
});
