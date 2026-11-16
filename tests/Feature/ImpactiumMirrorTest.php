<?php

declare(strict_types=1);

use FancyChat\Jobs\RunTurn;
use FancyChat\Prism\Tests\Support\FakeSession;
use FancyChat\Prism\Tests\Support\TestApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Subagents\RunContext;

/*
 * impactium's three failing cases, mirrored here against this adapter (their
 * fixture is reference data and is not run): an approval published early, a
 * length finish read as success (FinishReasonTest, with their exact fake), and
 * a decision during publication that left the turn parked.
 */

/** A session that raises one approval and records when its stream was fully read. */
function evidenceSession(bool &$exhausted, ?Closure $resume = null): FakeSession
{
    return new FakeSession(function (string $p, ?RunContext $c) use (&$exhausted): Generator {
        yield from runSteps($c, [
            ['step_start'],
            ['tool_call', ['toolCallId' => 'tc_1', 'name' => 'remember_fact', 'args' => (object) ['subject' => 'Marigold'], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
            ['approval_request', ['approvalId' => 'ap_1', 'toolCallId' => 'tc_1']],
            ['step_finish', ['text' => '']],
        ], 'run_root');
        event(new RunFinished('harness:fake', 'run_root', 'pause', 1, true, null, 'run_root'));
        // Harness records the run (and a TurnRecorder its evidence) once the stream is read to the end.
        $exhausted = true;
    }, $resume);
}

it('an approval is not published until the run\'s stream is exhausted (where Harness records the turn)', function (): void {
    TestApp::boot(['fancy-chat.store' => 'database']);
    $exhausted = false;
    $atInsert = null;
    DB::listen(function ($q) use (&$exhausted, &$atInsert): void {
        if (str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, 'fancy_chat_hitl')) {
            $atInsert ??= $exhausted;
        }
    });
    host(backendFor(evidenceSession($exhausted)));
    say('remember Marigold');
    expect($atInsert)->toBeTrue()
        ->and(lastTurn()['state'])->toBe('awaiting-human')
        ->and(chat()->snapshot('t_1')['approvals'][0]['approvalId'])->toBe('ap_1');
});

it('a decision during approval publication resumes the turn after it parks', function (): void {
    TestApp::boot(['fancy-chat.store' => 'database']);
    Queue::fake();
    $exhausted = false;
    $session = evidenceSession($exhausted, function (array $decisions, ?RunContext $c): Generator {
        yield from runSteps($c, [['text', ['delta' => 'Stored.']]], 'run_root', null, 'run_root', 10);
        event(new RunFinished('harness:fake', 'run_root', 'stop', 2, false, null, 'run_root'));
    });
    host(backendFor($session));
    $decided = false;
    $status = null;
    DB::listen(function ($q) use (&$decided, &$status): void {
        if (! $decided && str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, 'fancy_chat_hitl')) {
            $decided = true;
            $approval = chat()->snapshot('t_1')['approvals'][0]['approvalId'];
            $status = http('POST', "/fancy-chat/approvals/{$approval}/decision", ['optionId' => 'allow'])['status'];
        }
    });
    say('remember Marigold');
    Queue::pushed(RunTurn::class)->first()->handle(chat());

    expect($status)->toBe(200);
    Queue::assertPushed(RunTurn::class, 2);
    Queue::pushed(RunTurn::class)->last()->handle(chat());
    expect(lastTurn())->toMatchArray(['state' => 'settled', 'outcome' => 'completed'])
        ->and($session->resumes)->toHaveCount(1)
        ->and($session->resumes[0]['decisions'][0]->approved)->toBeTrue();
});
