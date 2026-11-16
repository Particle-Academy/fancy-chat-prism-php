<?php

declare(strict_types=1);

use FancyChat\Prism\HarnessBackend;
use FancyChat\Prism\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

/*
 * A run that ENDS badly is a failed turn, not a completed one. Harness fires
 * RunFinished for every finish reason except error/unknown (RunFailed), and
 * fancy-chat auto-finishes a turn whose stream simply ends, so the adapter must
 * read the ROOT run's finish reason and settle the turn itself. Reported by
 * impactium against 6586c87 with exactly this fake, through the real RunTurn.
 */

beforeEach(function (): void {
    TestApp::boot(harness: true);
});

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

function finishSession(): Session
{
    $user = new class(['id' => 42]) extends Model
    {
        protected $table = 'participants';

        protected $guarded = [];
    };
    $user->exists = true;

    return PrismHarness::session($user, 'chat-t_1');
}

it('a length-truncated run settles the turn as failed with output_limit (impactium\'s exact fake)', function (): void {
    Prism::fake([TextResponseFake::make()->withText('Partial')->withFinishReason(FinishReason::Length)]);
    $session = finishSession();
    host(new HarnessBackend(fn (string $threadId): Session => $session));
    say('write a long essay');

    expect(lastTurn())->toMatchArray(['state' => 'settled', 'outcome' => 'failed', 'reason' => 'output_limit']);
    // The partial text is kept, so the reader sees what arrived before the cut.
    expect(json_encode(chat()->snapshot('t_1')['messages']))->toContain('Partial');
});

it('settles every finish reason correctly', function (FinishReason $reason, string $outcome, ?string $code): void {
    Prism::fake([TextResponseFake::make()->withText('Some text')->withFinishReason($reason)]);
    $session = finishSession();
    host(new HarnessBackend(fn (string $threadId): Session => $session));
    say('go');

    expect(lastTurn())->toMatchArray(['state' => 'settled', 'outcome' => $outcome, 'reason' => $code]);
})->with([
    'stop' => [FinishReason::Stop, 'completed', null],
    'length' => [FinishReason::Length, 'failed', 'output_limit'],
    'content filter' => [FinishReason::ContentFilter, 'failed', 'content_filter'],
    'refusal' => [FinishReason::Refusal, 'refused', 'refusal'],
    'error' => [FinishReason::Error, 'failed', 'model_error'],
    'unknown' => [FinishReason::Unknown, 'failed', 'finish_unknown'],
    'other' => [FinishReason::Other, 'failed', 'finish_other'],
    'tool calls left unanswered (step limit)' => [FinishReason::ToolCalls, 'failed', 'step_limit'],
    'pause with no approval to wait on' => [FinishReason::Pause, 'failed', 'finish_pause'],
]);

it('a SUBAGENT run that ends badly does not settle the parent turn by itself', function (): void {
    // Only the root run's end decides the turn; a sub-run's end is reported as run.finished.
    expect(HarnessBackend::turnEnd('length', null, isRoot: false))->toBeNull()
        ->and(HarnessBackend::turnEnd('length', null, isRoot: true))->toBe(['action' => 'fail', 'reason' => 'output_limit']);
});
