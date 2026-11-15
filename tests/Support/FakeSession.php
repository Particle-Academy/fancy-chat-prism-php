<?php

declare(strict_types=1);

namespace FancyChat\Prism\Tests\Support;

use Closure;
use Generator;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

/**
 * A Harness Session whose run is scripted. It extends the REAL Session, so the
 * backend is typed against Harness's own class, and the script yields REAL
 * `RunStep`s and dispatches REAL `RunStarted`/`RunFinished`/... events, exactly
 * as AgentRuntime does mid-stream. Only the model call is replaced.
 *
 * The parent constructor is skipped on purpose: it builds stores and an
 * address, and nothing here reads them.
 */
class FakeSession extends Session
{
    /** @var list<array{prompt: string, context: ?RunContext}> */
    public array $streams = [];

    /** @var list<array{decisions: list<ToolApprovalResponse>, context: ?RunContext}> */
    public array $resumes = [];

    /** @var array<string, mixed>|null */
    public ?array $runState = null;

    /**
     * @param  Closure(string, ?RunContext): iterable<mixed>  $script  the first run
     * @param  (Closure(list<ToolApprovalResponse>, ?RunContext): iterable<mixed>)|null  $resume  the continuation
     */
    public function __construct(
        private Closure $script,
        private ?Closure $resume = null,
        private string $sessionKey = 'harness:fake',
    ) {}

    public function key(): string
    {
        return $this->sessionKey;
    }

    public function run(): ?array
    {
        return $this->runState;
    }

    public function streamSteps(string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        $this->streams[] = ['prompt' => $prompt, 'context' => $context];
        foreach (($this->script)($prompt, $context) as $step) {
            yield $step;
        }
    }

    public function streamDecision(array $decisions, ?RunContext $context = null): Generator
    {
        $this->resumes[] = ['decisions' => $decisions, 'context' => $context];
        foreach (($this->resume ?? fn (): array => [])($decisions, $context) as $step) {
            yield $step;
        }
    }
}
