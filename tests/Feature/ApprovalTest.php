<?php

declare(strict_types=1);

use FancyChat\Prism\Tests\Support\FakeSession;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

/** A run that asks to send an email and parks; its continuation is scripted. */
function approvalSession(?Closure $resume = null, int $approvals = 1): FakeSession
{
    return new FakeSession(function (string $p, ?RunContext $c) use ($approvals): array {
        $script = [['step_start']];
        for ($i = 1; $i <= $approvals; $i++) {
            $script[] = ['tool_call', ['toolCallId' => "tc_{$i}", 'name' => 'send_email', 'args' => (object) ['to' => "team{$i}"], 'startedAt' => '2026-11-15T10:00:01.000000Z']];
            $script[] = ['approval_request', ['approvalId' => "ap_{$i}", 'toolCallId' => "tc_{$i}"]];
        }
        $script[] = ['step_finish', ['text' => '']];

        return runSteps($c, $script);
    }, $resume);
}

it('maps approval_request to an approval record built from its tool call, and parks the turn', function (): void {
    host(backendFor(approvalSession()));
    say('email the team');

    $requested = only(['approval.requested']);
    expect($requested)->toHaveCount(1);
    $approval = $requested[0]['data']['approval'];
    expect($approval['approvalId'])->toBe('ap_1')
        ->and($approval['toolCallId'])->toBe('tc_1')
        ->and($approval['runId'])->toBe('run_1')
        ->and($approval['title'])->toBe('Allow send_email?')
        ->and($approval['proposal'])->toBe(['name' => 'send_email', 'args' => ['to' => 'team1']])
        ->and(array_map(fn (array $o): array => [$o['id'], $o['kind']], $approval['options']))->toBe([['allow', 'allow-once'], ['deny', 'reject-once']])
        ->and($approval['preview'][0])->toMatchArray(['kind' => 'code', 'language' => 'json']);
    expect(json_decode($approval['preview'][0]['code'], true))->toBe(['to' => 'team1']);

    expect(lastTurn()['state'])->toBe('awaiting-human');
    expect(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('pending');
});

it('lets the host word the approval', function (): void {
    host(backendFor(approvalSession(), ['present' => fn (array $call): array => [
        'title' => 'Darf ich '.$call['name'].' ausführen?',
        'summary' => 'An '.$call['args']['to'],
        'options' => [['id' => 'ja', 'kind' => 'allow-once', 'label' => 'Ja'], ['id' => 'nein', 'kind' => 'reject-once', 'label' => 'Nein']],
    ]]));
    say('email');

    $approval = chat()->snapshot('t_1')['approvals'][0];
    expect($approval['title'])->toBe('Darf ich send_email ausführen?')
        ->and($approval['summary'])->toBe('An team1')
        ->and(array_column($approval['options'], 'label'))->toBe(['Ja', 'Nein']);
});

it('resumes through streamDecision with the human decision, actor and turn, and maps the continuation', function (): void {
    $session = approvalSession(fn (array $decisions, ?RunContext $c): array => runSteps($c, [
        ['decision_recorded', ['approvalId' => 'ap_1', 'verdict' => 'approved', 'reason' => 'go ahead', 'actor' => $c?->actor]],
        ['step_start'],
        ['tool_result', ['toolCallId' => 'tc_1', 'ok' => true, 'result' => 'sent', 'refused' => false, 'durationMs' => 30, 'durationExact' => false]],
        ['text', ['delta' => 'Sent.']],
        ['step_finish', ['text' => 'Sent.']],
    ], from: 5));
    host(backendFor($session));
    $turnId = say('email the team');

    $r = http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'allow', 'note' => 'go ahead']);
    expect($r['status'])->toBe(200);

    expect($session->resumes)->toHaveCount(1);
    $decisions = $session->resumes[0]['decisions'];
    expect($decisions)->toHaveCount(1)
        ->and($decisions[0])->toBeInstanceOf(ToolApprovalResponse::class)
        ->and([$decisions[0]->approvalId, $decisions[0]->approved, $decisions[0]->reason])->toBe(['ap_1', true, 'go ahead'])
        ->and($session->resumes[0]['context']?->actor)->toBe('human:u_1')
        ->and($session->resumes[0]['context']?->turnId)->toBe($turnId);

    $recorded = only(['x-fancy-chat-prism.decision-recorded']);
    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]['data'])->toMatchArray(['approvalId' => 'ap_1', 'verdict' => 'approved', 'reason' => 'go ahead', 'actor' => 'human:u_1'])
        ->and($recorded[0]['data']['harness']['seq'])->toBe(5);
    expect(only(['tool.result'])[0]['data'])->toMatchArray(['toolCallId' => 'tc_1', 'outcome' => 'ok', 'output' => 'sent']);
    expect(lastTurn()['state'])->toBe('settled')->and(lastTurn()['outcome'])->toBe('completed');
});

it('hands a rejection to Harness as a denial', function (): void {
    $session = approvalSession(fn (array $d, ?RunContext $c): array => runSteps($c, [
        ['decision_recorded', ['approvalId' => 'ap_1', 'verdict' => 'denied', 'reason' => null, 'actor' => $c?->actor]],
        ['tool_result', ['toolCallId' => 'tc_1', 'ok' => false, 'error' => ['code' => 'approval_denied', 'message' => 'denied'], 'refused' => true, 'durationMs' => 0, 'durationExact' => false]],
    ], from: 5));
    host(backendFor($session));
    say('email the team');
    http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'deny']);

    $d = $session->resumes[0]['decisions'][0];
    expect([$d->approvalId, $d->approved, $d->reason])->toBe(['ap_1', false, null])
        ->and(only(['tool.result'])[0]['data']['outcome'])->toBe('refusal');
});

it('answers every approval the run parked on together, in one continuation', function (): void {
    $session = approvalSession(fn (array $d, ?RunContext $c): array => [], 2);
    host(backendFor($session));
    say('email both teams');
    expect(chat()->snapshot('t_1')['approvals'])->toHaveCount(2);

    http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'allow']);
    expect($session->resumes)->toBe([]); // still waiting on ap_2
    http('POST', '/fancy-chat/approvals/ap_2/decision', ['optionId' => 'deny', 'note' => 'not them']);

    expect($session->resumes)->toHaveCount(1);
    $decisions = array_map(fn (ToolApprovalResponse $r): array => [$r->approvalId, $r->approved, $r->reason], $session->resumes[0]['decisions']);
    expect($decisions)->toBe([['ap_1', true, null], ['ap_2', false, 'not them']]);
});

it('never resumes Harness on an approval it did not raise', function (): void {
    $session = approvalSession();
    host(backendFor($session));
    $turnId = say('email the team');

    // A resume carrying a record from somewhere else (no Harness approval behind it).
    $backend = backendFor($session);
    $events = iterator_to_array($backend->start(new FancyChat\Backend\TurnInput('t_1', $turnId, 'resume', null, [], null, ['questionId' => 'q_1', 'state' => 'answered'])), false);

    expect($session->resumes)->toBe([])
        ->and($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('turn.settled')
        ->and($events[0]['data'])->toBe(['action' => 'fail', 'reason' => 'harness.no-decisions']);
});

it('maps a resumed run to run-resumed, never a second run.started', function (): void {
    $session = approvalSession(function (array $d, ?RunContext $c): Generator {
        event(new Prism\Harness\Events\RunResumed('harness:fake', 'run_1', 'default', 'anthropic', 'claude', null, 'run_1'));
        yield from runSteps($c, [['text', ['delta' => 'Sent.']]], from: 5);
        event(new Prism\Harness\Events\RunFinished('harness:fake', 'run_1', 'stop', 1, false, null, 'run_1'));
    });
    host(backendFor($session));
    say('email the team');
    http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'allow']);

    expect(array_column(only(['run.started', 'x-fancy-chat-prism.run-resumed', 'run.finished']), 'type'))->toBe(['x-fancy-chat-prism.run-resumed', 'run.finished'])
        ->and(only(['x-fancy-chat-prism.run-resumed'])[0]['data'])->toBe(['mode' => 'default', 'provider' => 'anthropic', 'model' => 'claude']);
});

it('answers only approvals Harness raised, never one another source placed in the turn', function (): void {
    $session = approvalSession(fn (array $d, ?RunContext $c): array => []);
    host(backendFor($session));
    $turnId = say('email the team');
    // A second approval in the same turn that did not come from Harness.
    chat()->append('t_1', ['type' => 'approval.requested', 'turnId' => $turnId, 'actor' => ['kind' => 'agent', 'id' => 'other'], 'data' => ['approval' => [
        'approvalId' => 'ap_foreign', 'title' => 'Other?', 'summary' => '', 'turnId' => $turnId, 'preview' => [],
        'options' => [['id' => 'allow', 'kind' => 'allow-once', 'label' => 'Allow']],
    ]]]);
    http('POST', '/fancy-chat/approvals/ap_foreign/decision', ['optionId' => 'allow']);
    http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'allow']);

    expect($session->resumes)->toHaveCount(1)
        ->and(array_map(fn (ToolApprovalResponse $r): string => $r->approvalId, $session->resumes[0]['decisions']))->toBe(['ap_1']);
});

it('answers only the resumed turn\'s approvals, never an earlier turn\'s', function (): void {
    $session = new FakeSession(function (string $p, ?RunContext $c) use (&$session): array {
        $n = count($session->streams);

        return runSteps($c, [
            ['tool_call', ['toolCallId' => "tc_{$n}", 'name' => 'send_email', 'args' => (object) [], 'startedAt' => '2026-11-15T10:00:01.000000Z']],
            ['approval_request', ['approvalId' => "ap_{$n}", 'toolCallId' => "tc_{$n}"]],
        ], "run_{$n}");
    }, fn (array $d, ?RunContext $c): array => []);
    host(backendFor($session));

    say('first');
    http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'allow']);
    say('second');
    http('POST', '/fancy-chat/approvals/ap_2/decision', ['optionId' => 'deny']);

    expect($session->resumes)->toHaveCount(2)
        ->and(array_map(fn (ToolApprovalResponse $r): array => [$r->approvalId, $r->approved], $session->resumes[1]['decisions']))->toBe([['ap_2', false]]);
});
