<?php

declare(strict_types=1);

use FancyChat\Prism\HarnessBackend;
use FancyChat\Prism\Tests\Support\FakeSession;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Subagents\RunContext;

it('reads the contract version Harness itself declares', function (): void {
    expect(HarnessBackend::CONTRACT_VERSION)->toBe('g282.1')
        ->and(RunStep::CONTRACT_VERSION)->toBe(HarnessBackend::CONTRACT_VERSION);
});

it('fails the turn loudly on a step of another contract version, naming it, and renders nothing of it', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => [
        runSteps($c, [['text', ['delta' => 'fine']]])[0],
        ['contractVersion' => 'g282.2', 'runId' => 'run_1', 'parentRunId' => null, 'rootRunId' => 'run_1', 'turnId' => $c?->turnId,
            'seq' => 2, 'at' => '2026-11-15T10:00:00.000000Z', 'type' => 'text', 'delta' => 'MISRENDERED'],
        runSteps($c, [['text', ['delta' => 'after']]], from: 3)[0],
    ])));
    say('go');

    $turn = lastTurn();
    expect($turn['state'])->toBe('settled')
        ->and($turn['outcome'])->toBe('failed')
        ->and($turn['reason'])->toBe('harness.contract-version: got "g282.2", this adapter reads "g282.1"');
    expect(json_encode(events()))->not->toContain('MISRENDERED')->not->toContain('after');
});

it('fails on a step with no contract version at all', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => [
        ['runId' => 'run_1', 'seq' => 1, 'type' => 'text', 'delta' => 'MISRENDERED'],
    ])));
    say('go');

    expect(lastTurn()['outcome'])->toBe('failed')
        ->and(lastTurn()['reason'])->toBe('harness.contract-version: got none, this adapter reads "g282.1"')
        ->and(json_encode(events()))->not->toContain('MISRENDERED');
});

it('accepts a wire-shaped step array of the right version', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => [
        runSteps($c, [['text', ['delta' => 'wire']]])[0]->toArray(),
    ])));
    say('go');

    expect(lastTurn()['outcome'])->toBe('completed')
        ->and(only(['part.delta'])[0]['data']['text'])->toBe('wire');
});

it('refuses something that is not a step at all', function (): void {
    host(backendFor(new FakeSession(fn (string $p, ?RunContext $c): array => ['not a step'])));
    say('go');

    expect(lastTurn()['outcome'])->toBe('failed')
        ->and(lastTurn()['reason'])->toBe('harness.contract-version: got none, this adapter reads "g282.1"');
});

it('stops at the version failure: nothing after it is yielded, even to a caller that keeps reading', function (): void {
    $session = new FakeSession(fn (string $p, ?RunContext $c): array => [
        ['contractVersion' => 'g282.2', 'runId' => 'run_1', 'seq' => 1, 'type' => 'text', 'delta' => 'x'],
        runSteps($c, [['text', ['delta' => 'after']]], from: 2)[0],
    ]);
    $events = iterator_to_array(backendFor($session)->start(new FancyChat\Backend\TurnInput('t_1', 'u_1', 'message', 'm_1', [['id' => 'a', 'kind' => 'text', 'format' => 'plain', 'text' => 'go']])), false);

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('turn.settled')
        ->and($events[0]['data']['action'])->toBe('fail');
});
