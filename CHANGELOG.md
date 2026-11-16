# Changelog

All notable changes to this project are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**This package is pre-1.0, so breaking changes land in MINOR releases.** The
version number is not a stability promise until 1.0; read the entry.

## [Unreleased]

### Removed

- **Merged into `particle-academy/fancy-chat-php` 0.2.0, with the same class names.
  No further releases.** Run `composer remove particle-academy/fancy-chat-prism-php`
  and require `particle-academy/fancy-chat-php:^0.2`; no code changes.

### Changed

- **`composer.json` declares `"abandoned": "particle-academy/fancy-chat-php"`**, Composer's
  own marker, so tooling that reads the manifest knows to use the replacement.

- **`particle-academy/fancy-chat-php` is capped at `>=0.1.0 <0.2` on main**, an
  exception to the sibling-range rule: 0.2.0 contains this package's classes and
  replaces it, so the two cannot be installed together. Released 0.1.0 is unchanged.

## [0.1.0] - 2026-11-15

### Changed

- **Requires `particle-academy/fancy-chat-php` `>=0.1.0 <2.0`**, its first release,
  instead of `dev-main`. The Composer `vcs` repository entry stays only until
  fancy-chat-php is on Packagist (a root-only setting, so a consumer needs Packagist).

### Added

- **`KnowledgeBridge`, optional:** routes serving fancy-chat-ui's knowledge explorer
  from prism-graph 0.3 (`search`, `listSources`, `listChunks`, `getChunks`, `facts`,
  `factTimeline`; `listFacts`, ingest jobs and worker status pass through once
  prism-graph ships them, 501 until then). The scope comes from a host resolver,
  never the client. Search hits and chunks by id are hydrated into full chunk items
  through `ScopedGraph::listChunks`. Listings prism-graph does not stamp are stamped
  `g282.1`. Accept and reject exist only with a host `decider` and take a human actor
  only. prism-graph is suggested, not required. Nothing to do unless you want it.
- README: how a run's end settles the turn.

### Fixed

- **A run that ended badly settled the turn as completed.** Harness fires
  `RunFinished` for every finish reason but error and unknown, and fancy-chat
  completes a turn whose stream simply ends, so a length-truncated answer read as a
  finished one (reported by impactium). The ROOT run's end now settles the turn:
  `stop` completes; `length` fails `output_limit`; `content-filter` fails
  `content_filter`; `refusal` settles `refused` (`refusal`); `error` fails
  `model_error`; `unknown` fails `finish_unknown`; `other` fails `finish_other`;
  `tool-calls` and `pause` park when the run raised approvals and otherwise fail
  `step_limit` / `finish_pause`; any reason not listed fails `finish_unknown`.
  `RunFailed` fails `model_error`, `finish_unknown`, `budget` or `run_failed`.
  A sub-run's end never settles the turn. The partial text is kept. Nothing to do
  unless you relied on a truncated turn reading as completed.

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
