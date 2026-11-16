<?php

declare(strict_types=1);

use FancyChat\Prism\HarnessBackend;
use FancyChat\Prism\Tests\Support\FakeSession;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Subagents\RunContext;

/*
 * Which run's end settles the turn, against a scripted session.
 */

it('a sub-run that ends badly does not fail the turn; only the root run decides', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        yield from runSteps($c, [['text', ['delta' => 'child']]], 'run_child', 'run_root', 'run_root');
        event(new RunFinished('harness:fake', 'run_child', 'length', 1, false, 'run_root', 'run_root'));
        yield from runSteps($c, [['text', ['delta' => ' root']]], 'run_root', null, 'run_root', 2);
    })));
    say('go');
    // No root RunFinished arrived: the stream's end completes the turn, and the
    // sub-run's length finish must not be read as the turn's.
    expect(lastTurn())->toMatchArray(['state' => 'settled', 'outcome' => 'completed']);
});

it('a root run that raised an approval but ended on length fails rather than parks', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        yield from runSteps($c, [
            ['tool_call', ['toolCallId' => 'tc_1', 'name' => 'send', 'args' => new stdClass, 'startedAt' => '2026-11-15T10:00:00.000000Z']],
            ['approval_request', ['approvalId' => 'ap_1', 'toolCallId' => 'tc_1']],
        ], 'run_root');
        event(new RunFinished('harness:fake', 'run_root', 'length', 1, false, null, 'run_root'));
    })));
    say('go');
    expect(lastTurn())->toMatchArray(['state' => 'settled', 'outcome' => 'failed', 'reason' => 'output_limit']);
});

it('a root run paused on its approval parks', function (): void {
    host(backendFor(new FakeSession(function (string $p, ?RunContext $c): Generator {
        yield from runSteps($c, [
            ['tool_call', ['toolCallId' => 'tc_1', 'name' => 'send', 'args' => new stdClass, 'startedAt' => '2026-11-15T10:00:00.000000Z']],
            ['approval_request', ['approvalId' => 'ap_1', 'toolCallId' => 'tc_1']],
        ], 'run_root');
        event(new RunFinished('harness:fake', 'run_root', 'pause', 1, true, null, 'run_root'));
    })));
    say('go');
    expect(lastTurn()['state'])->toBe('awaiting-human');
});

it('a finish reason it does not recognise is a failure, never success', function (): void {
    expect(HarnessBackend::turnEnd('brand-new-reason', null, true))->toBe(['action' => 'fail', 'reason' => 'finish_unknown'])
        ->and(HarnessBackend::turnEnd('stop', null, true))->toBeNull()
        ->and(HarnessBackend::turnEnd(null, 'budget', true))->toBe(['action' => 'fail', 'reason' => 'budget'])
        ->and(HarnessBackend::turnEnd(null, 'RuntimeException', true))->toBe(['action' => 'fail', 'reason' => 'run_failed']);
});
