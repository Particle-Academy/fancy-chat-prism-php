# Changelog

All notable changes to this project are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**This package is pre-1.0, so breaking changes land in MINOR releases.** The
version number is not a stability promise until 1.0; read the entry.

## [Unreleased]

### Added

- `FancyChat\Prism\HarnessBackend`, an `AgentBackend` for
  `particle-academy/fancy-chat-php` that runs each chat turn on a Prism Harness
  session. It maps `Session::streamSteps()` RunSteps (contract `g282.1`) to the
  neutral chat events: thinking blocks, assistant text parts, `tool.called`,
  `tool.result` (`ok` / `failure` / `refusal`, with `durationMs`,
  `durationExact` and `output`), `approval.requested` (the turn parks), a
  failed turn on an `error` step, and Harness's `RunStarted` / `RunFinished` /
  `RunFailed` events to `run.*` keeping `parentRunId` and `rootRunId`. Every
  mapped event keeps Harness's `runId`, `turnId` and `seq` in `data.harness`.
- Approval continuation: a resumed turn sends every decided Harness approval of
  that turn to `Session::streamDecision()` together, with the recorded human
  decider as the `RunContext` actor, and maps the continuation the same way
  (`decision_recorded` becomes `x-fancy-chat-prism.decision-recorded`).
- The version gate: a step whose `contractVersion` is not `g282.1` fails the
  turn with a reason naming the version, and nothing of it is rendered.
- Cancel: offered only when the Harness session has `cancel()` (Harness 0.26
  does not, so it reports `unsupported`); Harness outcomes pass through, and
  anything unknown is `uncertain`.
- Requires PHP `^8.4`, `illuminate/*` `^13.0`, `particle-academy/prism-harness`
  `^0.26` and `particle-academy/fancy-chat-php` (from git, `dev-main`, until it
  is published).
