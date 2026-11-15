<?php

declare(strict_types=1);

use FancyChat\Backend\CancelOutcome;
use FancyChat\Prism\HarnessBackend;
use FancyChat\Prism\Tests\Support\CancellableSession;
use FancyChat\Prism\Tests\Support\FakeSession;
use Prism\Harness\Sessions\Session;

function cancellable(): CancellableSession
{
    $session = new CancellableSession(fn (): array => []);
    $session->runState = ['id' => 'run_7', 'status' => 'running'];

    return $session;
}

it('reports cancel unsupported while the Harness session has no cancel, and approvals supported', function (): void {
    expect(method_exists(Session::class, 'cancel'))->toBeFalse(); // Harness 0.26: G-285 has not shipped

    $backend = backendFor(new FakeSession(fn (): array => []));
    expect($backend->capabilities())->toBe(['steering' => false, 'cancel' => false, 'approvals' => true])
        ->and($backend->cancel('t_1', 'u_1', 'stop', 2000))->toBe(CancelOutcome::UNSUPPORTED);
});

it('reports cancel supported once the session class has it', function (): void {
    $backend = backendFor(cancellable(), ['sessionClass' => CancellableSession::class]);
    expect($backend->capabilities()['cancel'])->toBeTrue();
});

it('cancels the session current run and passes each Harness outcome through', function (string $harness, string $expected): void {
    $session = cancellable();
    $session->outcome = $harness;
    $backend = backendFor($session, ['sessionClass' => CancellableSession::class]);

    expect($backend->cancel('t_1', 'u_1', 'stop', 2000))->toBe($expected)
        ->and($session->cancelled)->toBe(['run_7']);
})->with([
    ['ok', CancelOutcome::OK],
    ['not-running', CancelOutcome::NOT_RUNNING],
    ['no-turn', CancelOutcome::NO_TURN],
    ['unsupported', CancelOutcome::UNSUPPORTED],
    ['refused', CancelOutcome::REFUSED],
    ['something-new', CancelOutcome::UNCERTAIN],
]);

it('reads a backed-enum outcome by its value', function (): void {
    $session = cancellable();
    $session->outcome = CancelFixtureOutcome::Refused;

    expect(backendFor($session, ['sessionClass' => CancellableSession::class])->cancel('t_1', 'u_1', 'interrupt', 2000))->toBe(CancelOutcome::REFUSED);
});

it('says no-turn when the session has no run, without calling Harness', function (): void {
    $session = cancellable();
    $session->runState = null;

    expect(backendFor($session, ['sessionClass' => CancellableSession::class])->cancel('t_1', 'u_1', 'stop', 2000))->toBe(CancelOutcome::NO_TURN)
        ->and($session->cancelled)->toBe([]);
});

it('leaves a parked run for Harness to judge, rather than calling it not running', function (): void {
    // A run parked on approval is not `running`, yet G-285 cancels it (its
    // parked approvals then read as settled-cancelled). Harness decides.
    $session = cancellable();
    $session->runState = ['id' => 'run_7', 'status' => 'completed'];

    expect(backendFor($session, ['sessionClass' => CancellableSession::class])->cancel('t_1', 'u_1', 'stop', 2000))->toBe(CancelOutcome::OK)
        ->and($session->cancelled)->toBe(['run_7']);
});

it('is uncertain when the cancel itself throws', function (): void {
    $session = new class(fn (): array => []) extends FakeSession
    {
        public function cancel(string $runId): string
        {
            throw new RuntimeException('store down');
        }
    };
    $session->runState = ['id' => 'run_7', 'status' => 'running'];

    expect(backendFor($session, ['sessionClass' => $session::class])->cancel('t_1', 'u_1', 'stop', 2000))->toBe(CancelOutcome::UNCERTAIN);
});

it('settles a running turn as cancelled through the chat server when Harness says ok', function (): void {
    $session = cancellable();
    host(backendFor($session, ['sessionClass' => CancellableSession::class]));
    // A turn left parked on an approval, so it is still active when the human stops it.
    $session->runState = ['id' => 'run_7', 'status' => 'running'];
    chat()->append('t_1', ['type' => 'turn.started', 'turnId' => 'u_9', 'data' => ['turnId' => 'u_9']]);
    chat()->append('t_1', ['type' => 'turn.transition', 'turnId' => 'u_9', 'data' => ['turnId' => 'u_9', 'action' => 'start']]);

    $r = http('POST', '/fancy-chat/threads/t_1/cancel', ['mode' => 'stop']);
    expect($r['body']['outcome'] ?? null)->toBe('ok')
        ->and(lastTurn()['outcome'])->toBe('cancelled');
});

enum CancelFixtureOutcome: string
{
    case Refused = 'refused';
}

it('is a HarnessBackend', function (): void {
    expect(backendFor(new FakeSession(fn (): array => [])))->toBeInstanceOf(HarnessBackend::class);
});
