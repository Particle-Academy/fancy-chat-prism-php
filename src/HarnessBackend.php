<?php

declare(strict_types=1);

namespace FancyChat\Prism;

use BackedEnum;
use Closure;
use FancyChat\Backend\AgentBackend;
use FancyChat\Backend\CancelOutcome;
use FancyChat\Backend\TurnInput;
use FancyChat\FancyChat;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Prism\Harness\Events\HarnessEvent;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunResumed;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Throwable;

/**
 * fancy-chat-php's AgentBackend, run on a Prism Harness session.
 *
 * It reads Harness's tagged step stream (`Session::streamSteps()`, and
 * `Session::streamDecision()` on resume), contract `g282.1`, and yields the
 * chat server's neutral events. Harness's own run events (`RunStarted`,
 * `RunResumed`, `RunFinished`, `RunFailed`) are Laravel events dispatched while
 * the stream runs; they are collected for the session being streamed and
 * yielded in place, so `run.*` lands in the log where it happened.
 *
 * Nothing here decides an approval: a decision reaches Harness only after the
 * chat server recorded it from a human actor, and the actor handed to Harness
 * is the one the server recorded.
 */
final class HarnessBackend implements AgentBackend
{
    /** The RunStep contract this adapter reads. Any other version fails the turn. */
    public const string CONTRACT_VERSION = 'g282.1';

    /** The extension namespace for events with no core chat type. */
    public const string EXTENSION = 'x-fancy-chat-prism';

    /** Marks an approval record this adapter raised, so a resume only ever answers Harness's own. */
    public const string ORIGIN = 'prism-harness';

    private const array RUN_EVENTS = [RunStarted::class, RunResumed::class, RunFinished::class, RunFailed::class];

    /**
     * How a ROOT run's finish reason (Prism `FinishReason`, carried verbatim by
     * Harness's RunFinished) settles the chat turn. `stop` completes; `pause` and
     * `tool-calls` park when the run raised approvals, and are failures otherwise
     * (nothing to wait on: a pause with no request, or a run that stopped with its
     * tool calls unanswered, i.e. a step limit). Anything not listed fails as
     * `finish_unknown`: a reason we do not recognise is never read as success.
     */
    private const array FINISH = [
        'stop' => null,
        'length' => ['action' => 'fail', 'reason' => 'output_limit'],
        'content-filter' => ['action' => 'fail', 'reason' => 'content_filter'],
        'refusal' => ['action' => 'refuse', 'reason' => 'refusal'],
        'error' => ['action' => 'fail', 'reason' => 'model_error'],
        'unknown' => ['action' => 'fail', 'reason' => 'finish_unknown'],
        'other' => ['action' => 'fail', 'reason' => 'finish_other'],
        'tool-calls' => ['action' => 'fail', 'reason' => 'step_limit'],
        'pause' => ['action' => 'fail', 'reason' => 'finish_pause'],
    ];

    /** Root-run ends seen while streaming, per session key: [finishReason|null, settle|null]. */
    private array $rootEnds = [];

    /** @var Closure(string): Session */
    private Closure $session;

    /** @var Closure(TurnInput): string */
    private Closure $prompt;

    /** @var Closure(array{approvalId: string, toolCallId: ?string, name: ?string, args: array<string, mixed>}): array<string, mixed> */
    private Closure $present;

    private bool $listening = false;

    /** @var array<string, list<HarnessEvent>> run events seen per session key being streamed */
    private array $runEvents = [];

    /**
     * @param  Closure(string): Session  $session  the Harness session that serves a chat thread
     * @param  (Closure(TurnInput): string)|null  $prompt  the prompt for a new turn; default: its text parts
     * @param  (Closure(array{approvalId: string, toolCallId: ?string, name: ?string, args: array<string, mixed>}): array<string, mixed>)|null  $present
     *                                                                                                                                              the approval's title, summary and options; default: English
     * @param  class-string<Session>  $sessionClass  the class the resolver returns; cancel is offered only if it has `cancel()`
     */
    public function __construct(
        Closure $session,
        ?Closure $prompt = null,
        ?Closure $present = null,
        private string $sessionClass = Session::class,
        private ?Dispatcher $events = null,
    ) {
        $this->session = $session;
        $this->prompt = $prompt ?? self::textOf(...);
        $this->present = $present ?? self::english(...);
    }

    public function start(TurnInput $input): iterable
    {
        return $this->run($input);
    }

    public function cancel(string $threadId, string $turnId, string $mode, int $confirmWaitMs): string
    {
        // Harness's cancel is a durable flag the run loop reads before its next
        // step or tool, so it answers at once; there is nothing to wait for.
        if (! $this->supportsCancel()) {
            return CancelOutcome::UNSUPPORTED;
        }
        try {
            $session = ($this->session)($threadId);
            if (! method_exists($session, 'cancel')) {
                return CancelOutcome::UNSUPPORTED;
            }
            $run = $session->run();
            if (! is_array($run) || ! is_string($run['id'] ?? null)) {
                return CancelOutcome::NO_TURN;
            }
            $outcome = $session->cancel($run['id']);
        } catch (Throwable $e) {
            self::report($e);

            return CancelOutcome::UNCERTAIN;
        }
        $value = $outcome instanceof BackedEnum ? $outcome->value : $outcome;

        return in_array($value, CancelOutcome::ALL, true) ? $value : CancelOutcome::UNCERTAIN;
    }

    public function capabilities(): array
    {
        return ['steering' => false, 'cancel' => $this->supportsCancel(), 'approvals' => true];
    }

    private function supportsCancel(): bool
    {
        return method_exists($this->sessionClass, 'cancel');
    }

    /** @return Generator<int, array<string, mixed>> */
    private function run(TurnInput $input): Generator
    {
        $session = ($this->session)($input->threadId);
        if ($input->kind === 'resume') {
            $decisions = $this->decisions($input);
            if ($decisions === []) {
                yield self::fail('harness.no-decisions');

                return;
            }
            $context = RunContext::metadata($input->turnId, self::actorOf($input->approval['decidedBy'] ?? null));
            $open = fn (): iterable => $session->streamDecision($decisions, $context);
        } else {
            $prompt = ($this->prompt)($input);
            if (trim($prompt) === '') {
                yield self::fail('harness.empty-prompt');

                return;
            }
            $context = RunContext::metadata($input->turnId);
            $open = fn (): iterable => $session->streamSteps($prompt, null, [], $context);
        }

        yield from $this->map($session->key(), $open);
    }

    /**
     * Every decided approval this adapter raised in the turn, in log order.
     * Harness denies by default any parked call it has no answer for, so they
     * all go back together, never one at a time.
     *
     * @return list<ToolApprovalResponse>
     */
    private function decisions(TurnInput $input): array
    {
        $records = [];
        $chat = Container::getInstance()->bound(FancyChat::class) ? Container::getInstance()->make(FancyChat::class) : null;
        if ($chat instanceof FancyChat) {
            foreach ($chat->snapshot($input->threadId)['approvals'] as $a) {
                if (($a['turnId'] ?? null) === $input->turnId && is_string($a['approvalId'] ?? null)) {
                    $records[$a['approvalId']] = $a;
                }
            }
        }
        if (is_array($input->approval) && is_string($input->approval['approvalId'] ?? null)) {
            $records[$input->approval['approvalId']] = $input->approval;
        }

        $out = [];
        foreach ($records as $id => $a) {
            if (($a['origin'] ?? null) !== self::ORIGIN || ! in_array($a['decision'] ?? null, ['approve', 'reject', 'edit'], true)) {
                continue;
            }
            $out[] = new ToolApprovalResponse((string) $id, $a['decision'] === 'approve', is_string($a['note'] ?? null) ? $a['note'] : null);
        }

        return $out;
    }

    /**
     * @param  Closure(): iterable<mixed>  $open
     * @return Generator<int, array<string, mixed>>
     */
    private function map(string $key, Closure $open): Generator
    {
        $this->listen();
        $this->runEvents[$key] = [];
        unset($this->rootEnds[$key]);
        $st = ['last' => null, 'thinking' => null, 'text' => null, 'calls' => [], 'approvals' => 0, 'failure' => null];
        try {
            try {
                foreach ($open() as $raw) {
                    yield from $this->flushRuns($key);
                    $step = $raw instanceof RunStep ? $raw->toArray() : $raw;
                    $version = is_array($step) ? ($step['contractVersion'] ?? null) : null;
                    if ($version !== self::CONTRACT_VERSION) {
                        yield from $this->closeAll($st);
                        yield self::fail('harness.contract-version: got '.(is_string($version) ? '"'.$version.'"' : 'none').', this adapter reads "'.self::CONTRACT_VERSION.'"');

                        return;
                    }
                    if (! yield from $this->mapStep($step, $st)) {
                        return;
                    }
                }
            } catch (Throwable $e) {
                // Close what is open and record the run's end, then let the
                // chat server fail the turn and report the exception: its text
                // may carry anything the provider said, so it is never stored.
                yield from $this->closeAll($st);
                yield from $this->flushRuns($key);

                throw $e;
            }
            yield from $this->closeAll($st);
            yield from $this->flushRuns($key);
            $end = $this->rootEnds[$key] ?? null;
            if ($st['failure'] !== null) {
                yield self::fail($st['failure']);
            } elseif ($st['approvals'] > 0 && ($end === null || in_array($end['finishReason'], ['pause', 'tool-calls'], true))) {
                yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
            } elseif ($end !== null && $end['settle'] !== null) {
                // The root run ended badly: settle it here, or fancy-chat would
                // auto-finish the turn as completed when the stream simply ends.
                yield ['type' => 'turn.settled', 'data' => $end['settle']];
            }
        } finally {
            unset($this->runEvents[$key], $this->rootEnds[$key]);
        }
    }

    /**
     * One step. Returns false when the turn has failed and the stream must stop.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $st
     * @return Generator<int, array<string, mixed>, mixed, bool>
     */
    private function mapStep(array $step, array &$st): Generator
    {
        $st['last'] = $step;
        $type = $step['type'] ?? null;
        if ($type === RunStep::THINKING) {
            yield from $this->closeText($st);
            if ($st['thinking'] === null) {
                $st['thinking'] = $step;
                yield self::event($step, 'thinking.started', []);
            }
            yield self::event($step, 'thinking.delta', ['text' => self::str($step['delta'] ?? '')]);

            return true;
        }
        if ($type === RunStep::TEXT) {
            yield from $this->closeThinking($st);
            if ($st['text'] === null) {
                $messageId = 'msg_'.self::str($step['runId'] ?? '').'_'.(int) ($step['seq'] ?? 0);
                $st['text'] = ['step' => $step, 'messageId' => $messageId, 'partId' => 'text'];
                yield self::event($step, 'message.created', ['message' => ['id' => $messageId, 'role' => 'assistant', 'parts' => []]]);
                yield self::event($step, 'part.started', ['messageId' => $messageId, 'part' => ['id' => 'text', 'kind' => 'text', 'format' => 'markdown', 'text' => '']]);
            }
            yield self::event($step, 'part.delta', ['messageId' => $st['text']['messageId'], 'partId' => $st['text']['partId'], 'text' => self::str($step['delta'] ?? '')]);

            return true;
        }

        yield from $this->closeAll($st);
        switch ($type) {
            case RunStep::STEP_START:
            case RunStep::STEP_FINISH:
                // Boundaries only: what they bound is already closed above.
                return true;
            case RunStep::TOOL_CALL:
                $id = self::str($step['toolCallId'] ?? '');
                $args = self::plain($step['args'] ?? []);
                $st['calls'][$id] = ['name' => self::str($step['name'] ?? ''), 'args' => $args];
                yield self::event($step, 'tool.called', [
                    'toolCallId' => $id,
                    'name' => self::str($step['name'] ?? ''),
                    'args' => $args,
                    'startedAt' => $step['startedAt'] ?? null,
                ]);

                return true;
            case RunStep::TOOL_RESULT:
                $refused = ($step['refused'] ?? null) === true;
                $ok = ($step['ok'] ?? null) === true && ! $refused;
                $error = is_array($step['error'] ?? null) ? $step['error'] : [];
                $data = [
                    'toolCallId' => self::str($step['toolCallId'] ?? ''),
                    'outcome' => $refused ? 'refusal' : ($ok ? 'ok' : 'failure'),
                    'output' => $ok ? self::str($step['result'] ?? '') : self::str($error['message'] ?? ''),
                    'durationExact' => ($step['durationExact'] ?? null) === true,
                ];
                if (is_int($step['durationMs'] ?? null)) {
                    $data['durationMs'] = $step['durationMs'];
                }
                if (! $ok) {
                    $data['errorCode'] = self::str($error['code'] ?? '');
                }
                yield self::event($step, 'tool.result', $data);

                return true;
            case RunStep::APPROVAL_REQUEST:
                $toolCallId = is_string($step['toolCallId'] ?? null) ? $step['toolCallId'] : null;
                $call = $toolCallId !== null ? ($st['calls'][$toolCallId] ?? null) : null;
                $request = [
                    'approvalId' => self::str($step['approvalId'] ?? ''),
                    'toolCallId' => $toolCallId,
                    'name' => $call['name'] ?? null,
                    'args' => $call['args'] ?? [],
                ];
                $approval = array_merge(
                    ['preview' => [['id' => 'args', 'kind' => 'code', 'language' => 'json', 'code' => (string) json_encode((object) $request['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]],
                    ($this->present)($request),
                    [
                        'approvalId' => $request['approvalId'],
                        'toolCallId' => $toolCallId,
                        'proposal' => ['name' => $request['name'], 'args' => $request['args']],
                        'origin' => self::ORIGIN,
                    ],
                );
                $st['approvals']++;
                yield self::event($step, 'approval.requested', ['approval' => $approval]);

                return true;
            case RunStep::ERROR:
                $error = is_array($step['error'] ?? null) ? $step['error'] : [];
                // The code only: Harness already replaced the provider's message.
                $st['failure'] ??= 'harness.error: '.(is_string($error['code'] ?? null) && $error['code'] !== '' ? $error['code'] : 'unknown');

                return true;
            case RunStep::DECISION_RECORDED:
                yield self::event($step, self::EXTENSION.'.decision-recorded', [
                    'approvalId' => self::str($step['approvalId'] ?? ''),
                    'verdict' => $step['verdict'] ?? null,
                    'reason' => is_string($step['reason'] ?? null) ? $step['reason'] : null,
                    'actor' => is_string($step['actor'] ?? null) ? $step['actor'] : null,
                ]);

                return true;
        }

        yield self::fail('harness.step-type: '.(is_string($type) ? $type : 'none'));

        return false;
    }

    /**
     * @param  array<string, mixed>  $st
     * @return Generator<int, array<string, mixed>>
     */
    private function closeAll(array &$st): Generator
    {
        yield from $this->closeThinking($st);
        yield from $this->closeText($st);
    }

    /**
     * @param  array<string, mixed>  $st
     * @return Generator<int, array<string, mixed>>
     */
    private function closeThinking(array &$st): Generator
    {
        if ($st['thinking'] !== null) {
            yield self::event($st['thinking'], 'thinking.completed', [], $st['last']);
            $st['thinking'] = null;
        }
    }

    /**
     * @param  array<string, mixed>  $st
     * @return Generator<int, array<string, mixed>>
     */
    private function closeText(array &$st): Generator
    {
        if ($st['text'] !== null) {
            yield self::event($st['text']['step'], 'part.completed', ['messageId' => $st['text']['messageId'], 'partId' => $st['text']['partId']], $st['last']);
            $st['text'] = null;
        }
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;
        $events = $this->events ?? Container::getInstance()->make('events');
        $events->listen(self::RUN_EVENTS, function (HarnessEvent $event): void {
            if (array_key_exists($event->sessionKey, $this->runEvents)) {
                $this->runEvents[$event->sessionKey][] = $event;
            }
        });
    }

    /** @return Generator<int, array<string, mixed>> */
    private function flushRuns(string $key): Generator
    {
        $pending = $this->runEvents[$key] ?? [];
        $this->runEvents[$key] = [];
        foreach ($pending as $event) {
            if ($event->parentRunId === null && ($event instanceof RunFinished || $event instanceof RunFailed)) {
                $this->rootEnds[$key] = $event instanceof RunFinished
                    ? ['finishReason' => $event->finishReason, 'settle' => self::turnEnd($event->finishReason, null, true)]
                    : ['finishReason' => null, 'settle' => self::turnEnd(null, $event->exception, true)];
            }
            $envelope = ['runId' => $event->runId, 'parentRunId' => $event->parentRunId, 'rootRunId' => $event->rootRunId ?? $event->runId];
            yield $envelope + match (true) {
                $event instanceof RunStarted => ['type' => 'run.started', 'data' => ['mode' => $event->mode, 'provider' => $event->provider, 'model' => $event->model]],
                $event instanceof RunResumed => ['type' => self::EXTENSION.'.run-resumed', 'data' => ['mode' => $event->mode, 'provider' => $event->provider, 'model' => $event->model]],
                $event instanceof RunFinished => ['type' => 'run.finished', 'data' => ['finishReason' => $event->finishReason, 'steps' => $event->steps, 'awaitingApproval' => $event->awaitingApproval]],
                $event instanceof RunFailed => ['type' => 'run.failed', 'data' => ['exception' => $event->exception]],
                default => ['type' => self::EXTENSION.'.run-event', 'data' => []],
            };
        }
    }

    /**
     * A neutral event for one step, carrying Harness's correlation: the run's
     * lineage on the envelope, and the step's own turn, seq and time in `data.harness`.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function event(array $step, string $type, array $data, ?array $cause = null): array
    {
        $runId = $step['runId'] ?? null;
        // A closing event belongs to the block's run but happened at the step
        // that closed it, so its seq keeps the log in Harness's order.
        $at = $cause ?? $step;

        return [
            'type' => $type,
            'runId' => $runId,
            'parentRunId' => $step['parentRunId'] ?? null,
            'rootRunId' => $step['rootRunId'] ?? $runId,
            'data' => $data + ['harness' => [
                'runId' => $at['runId'] ?? $runId,
                'turnId' => $at['turnId'] ?? null,
                'seq' => $at['seq'] ?? null,
                'at' => $at['at'] ?? null,
            ]],
        ];
    }

    /**
     * How a run's end settles the chat turn, or null when it does not (a sub-run,
     * or a root run that stopped normally). `$finishReason` is RunFinished's;
     * `$failure` is RunFailed's `exception` (a finish reason, `budget`, or a class
     * name, which is never stored).
     *
     * @return array{action: string, reason: string}|null
     */
    public static function turnEnd(?string $finishReason, ?string $failure, bool $isRoot): ?array
    {
        if (! $isRoot) {
            return null;
        }
        if ($failure !== null) {
            return ['action' => 'fail', 'reason' => match ($failure) {
                'error' => 'model_error',
                'unknown' => 'finish_unknown',
                'budget' => 'budget',
                default => 'run_failed',
            }];
        }
        if ($finishReason === null) {
            return null;
        }

        return array_key_exists($finishReason, self::FINISH) ? self::FINISH[$finishReason] : ['action' => 'fail', 'reason' => 'finish_unknown'];
    }

    /** @return array{type: string, data: array{action: string, reason: string}} */
    private static function fail(string $reason): array
    {
        return ['type' => 'turn.settled', 'data' => ['action' => 'fail', 'reason' => $reason]];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Harness gives tool arguments as an object (stdClass, nested); the chat
     * contract wants plain JSON arrays.
     *
     * @return array<string, mixed>
     */
    private static function plain(mixed $args): array
    {
        $decoded = json_decode((string) json_encode($args), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  mixed  $who  the chat server's recorded decider, {kind, id} */
    private static function actorOf(mixed $who): ?string
    {
        return is_array($who) && is_string($who['kind'] ?? null) && is_string($who['id'] ?? null) ? $who['kind'].':'.$who['id'] : null;
    }

    private static function textOf(TurnInput $input): string
    {
        $texts = [];
        foreach ($input->parts as $part) {
            if (($part['kind'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * @param  array{approvalId: string, toolCallId: ?string, name: ?string, args: array<string, mixed>}  $request
     * @return array<string, mixed>
     */
    private static function english(array $request): array
    {
        return [
            'title' => $request['name'] !== null && $request['name'] !== '' ? 'Allow '.$request['name'].'?' : 'Allow this tool call?',
            'summary' => '',
            'options' => [
                ['id' => 'allow', 'kind' => 'allow-once', 'label' => 'Allow'],
                ['id' => 'deny', 'kind' => 'reject-once', 'label' => 'Deny'],
            ],
        ];
    }

    private static function report(Throwable $e): void
    {
        if (function_exists('report')) {
            try {
                report($e);
            } catch (Throwable) {
                // Reporting must never change a cancel's outcome.
            }
        }
    }
}
