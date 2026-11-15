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
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/*
 * The REAL Harness: PrismHarness::session() -> Session::streamSteps() ->
 * AgentRuntime -> RunStepTagger, with only the model replaced by Prism's own
 * fake. This is what proves the field names the adapter reads are the ones
 * Harness 0.26 actually emits, rather than the ones the design note promised.
 */

beforeEach(function (): void {
    TestApp::boot(harness: true);
});

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

final class ChatParticipant extends Model
{
    protected $table = 'participants';

    protected $guarded = [];
}

function realSession(): Session
{
    $user = new ChatParticipant(['id' => 42]);
    $user->exists = true;

    return PrismHarness::session($user, 'chat-t_1');
}

it('maps a real Harness run: run events, step boundaries, text, a tool call and its result', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('Looking it up. ')
                    ->withToolCalls([new ToolCall('tc_real', 'search', ['q' => 'acme'])])
                    ->withToolResults([new ToolResult('tc_real', 'search', ['q' => 'acme'], '3 hits')])
                    ->withFinishReason(FinishReason::ToolCalls)
                    ->withUsage(new Usage(1, 1))
                    ->withMeta(new Meta('fake', 'fake-model')),
                TextStepFake::make()
                    ->withText('Acme is won.')
                    ->withFinishReason(FinishReason::Stop)
                    ->withUsage(new Usage(1, 1))
                    ->withMeta(new Meta('fake', 'fake-model')),
            ]))
            ->withText('Looking it up. Acme is won.')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(2, 2))
            ->withMeta(new Meta('fake', 'fake-model')),
    ]);

    $session = realSession();
    host(new HarnessBackend(fn (string $threadId): Session => $session));
    $turnId = say('how is acme?');

    expect(lastTurn()['state'])->toBe('settled')->and(lastTurn()['outcome'])->toBe('completed');

    $runs = only(['run.started', 'run.finished', 'run.failed']);
    expect(array_column($runs, 'type'))->toBe(['run.started', 'run.finished'])
        ->and($runs[0]['runId'])->toStartWith('run_')
        ->and($runs[0]['rootRunId'])->toBe($runs[0]['runId'])
        ->and($runs[1]['data']['finishReason'])->toBe('stop');

    $called = only(['tool.called'])[0]['data'];
    expect($called['toolCallId'])->toBe('tc_real')
        ->and($called['name'])->toBe('search')
        ->and($called['args'])->toBe(['q' => 'acme'])
        ->and($called['startedAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');

    $result = only(['tool.result'])[0]['data'];
    expect($result['toolCallId'])->toBe('tc_real')
        ->and($result['outcome'])->toBe('ok')
        ->and($result['output'])->toBe('3 hits')
        ->and($result['durationMs'])->toBeInt()
        ->and($result['durationExact'])->toBeBool();

    $assistant = array_values(array_filter(chat()->snapshot('t_1')['messages'], fn (array $m): bool => $m['role'] === 'assistant'));
    expect(implode('', array_map(fn (array $m): string => $m['parts'][0]['text'], $assistant)))->toBe('Looking it up. Acme is won.');

    // Correlation: Harness's turnId is the chat turn, and the log keeps Harness's order.
    $seqs = [];
    foreach (events() as $e) {
        if (isset($e['data']['harness'])) {
            expect($e['data']['harness']['turnId'])->toBe($turnId)->and($e['runId'])->toBe($runs[0]['runId']);
            $seqs[] = $e['data']['harness']['seq'];
        }
    }
    $sorted = $seqs;
    sort($sorted);
    expect($seqs)->not->toBeEmpty()->and($seqs)->toBe($sorted)->and(min($seqs))->toBeGreaterThan(1); // seq 1 is the step_start boundary
});
