# particle-academy/fancy-chat-prism-php

The **OPTIONAL** Prism Harness adapter for
[`particle-academy/fancy-chat-php`](https://github.com/Particle-Academy/fancy-chat-php),
the provider-neutral Laravel chat server of the Fancy agent-chat framework
(council change CC-fancy-0042).

`fancy-chat-php` has no Prism dependency and never will. This package is the
other side of that line: an `AgentBackend` that runs each chat turn on a
[Prism Harness](https://github.com/Particle-Academy/prism-harness) session and
maps what Harness streams onto the chat contract. Hosts that do not run Prism do
not install it.

**Not yet published.** It depends on `fancy-chat-php`, which is not on
Packagist yet, so both install from git for now.

## What it maps

It reads Harness's tagged step stream, `Session::streamSteps()` (and
`Session::streamDecision()` on resume), contract version **`g282.1`**:

| Harness | Chat event |
|---|---|
| `thinking` steps | `thinking.started`, `thinking.delta` (`text`), `thinking.completed`. A block closes at the next non-thinking step, so a run may hold several, in order. |
| `text` steps | `message.created` (an assistant message), `part.started` (a markdown text part), `part.delta` per delta, `part.completed` when the block closes. Text after a tool starts a new message, so it renders after the tool. |
| `tool_call` | `tool.called` `{toolCallId, name, args, startedAt}` |
| `tool_result` | `tool.result` `{toolCallId, outcome, durationMs, durationExact, output, errorCode?}`. `outcome` is `refusal` when Harness says `refused`, else `ok` or `failure`. `output` is the result, or `error.message` (what the model was given). `durationExact` is `false` unless Harness says the duration is exact. |
| `approval_request` | `approval.requested`, built from the tool call it gates (title, options, the arguments as a JSON preview), then the turn parks. |
| `decision_recorded` | `x-fancy-chat-prism.decision-recorded` `{approvalId, verdict, reason, actor}` |
| `error` | the turn fails, reason `harness.error: <code>` (the code only; Harness never forwards the provider's message) |
| `step_start`, `step_finish` | boundaries: they close any open thinking or text block |
| `RunStarted` / `RunFinished` / `RunFailed` | `run.started` / `run.finished` / `run.failed`, with `runId`, `parentRunId` and `rootRunId` |
| `RunResumed` | `x-fancy-chat-prism.run-resumed` (a resumed run is not a second start) |

Every mapped event carries the run lineage on the envelope and the step's own
`{runId, turnId, seq, at}` in `data.harness`, so Harness's ordering survives into
the chat log.

## The version gate

A step whose `contractVersion` is not `g282.1`, or that has none, fails the turn
loudly with reason `harness.contract-version: got "<version>", this adapter
reads "g282.1"`. Nothing from that step, or after it, is rendered. An unknown
step type within the version fails the same way (`harness.step-type: <type>`).
The shapes are agreed between Prism and Fancy and any change bumps the version,
so a step this adapter does not know is a step it must not guess at.

## Install

PHP 8.4, Laravel 13. Both packages install from git over https until they are
published:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Particle-Academy/fancy-chat-php.git" },
    { "type": "vcs", "url": "https://github.com/Particle-Academy/fancy-chat-prism-php.git" }
],
"require": {
    "particle-academy/fancy-chat-php": "dev-main",
    "particle-academy/fancy-chat-prism-php": "dev-main"
}
```

```bash
composer update particle-academy/fancy-chat-prism-php
php artisan migrate
```

`particle-academy/prism-harness` (`^0.26`) comes with it from Packagist.

## Wire it

In a service provider, bind the host as `fancy-chat-php` describes, and hand it
this backend with a resolver for the Harness session that serves each thread:

```php
use FancyChat\Facades\FancyChat;
use FancyChat\Prism\HarnessBackend;
use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\Sessions\Session;

FancyChat::actor(fn ($request) => $request->user() ? ['kind' => 'human', 'id' => (string) $request->user()->getKey()] : null)
    ->access(fn ($request, string $threadId, string $ability): bool => /* your policy */ true)
    ->backend(new HarnessBackend(
        session: fn (string $threadId): Session => PrismHarness::session(Thread::findOrFail($threadId)->owner, 'chat-'.$threadId),
    ));
```

Optional arguments:

- `prompt: fn (TurnInput $input): string`: the prompt for a new turn. The default
  joins the message's text parts.
- `present: fn (array $call): array`: the approval's `title`, `summary` and
  `options`, given `{approvalId, toolCallId, name, args}`. The default is English
  ("Allow send_email?", Allow / Deny, one-time options only); pass your own to
  translate it.
- `sessionClass`: the class your resolver returns, used to decide whether cancel
  is offered (below).

### Approvals

When Harness parks on approvals, each becomes a chat approval record and the
turn parks. A human decides through `fancy-chat-php`'s decision endpoint (human
actors only). When the turn resumes, every Harness approval of that turn goes
back to `Session::streamDecision()` together, because Harness denies by default
any parked call it has no answer for. `approve` is approved; `reject` (and
`edit`, which Harness cannot apply) is denied, with the human's note as the
reason. The `RunContext` actor is the decider the chat server recorded, as
`kind:id`. Approvals this adapter did not raise are never sent to Harness.

### Cancel

`capabilities()['cancel']` is true only when the session class has a
`cancel(string $runId)` method. Harness 0.26 does not have one yet (the G-285
durable cancel), so with 0.26 cancel reports `unsupported`. Once it ships,
the adapter cancels the session's current run and passes Harness's outcome
through (`ok`, `not-running`, `no-turn`, `unsupported`, `refused`); anything
else, or an exception, is `uncertain`. Both `stop` and `interrupt` map to the
same Harness cancel.

## Develop

```bash
composer update
vendor/bin/pest          # forced in-memory SQLite; provider keys blanked
php tests/sabotage.php   # breaks one mapping clause at a time; the suite must notice each
```

`tests/Integration/RealHarnessTest.php` runs a real Harness session with only
the model replaced by Prism's own fake.

MIT licence.
