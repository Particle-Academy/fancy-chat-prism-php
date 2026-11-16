# AGENTS.md — fancy-chat-prism-php

`CLAUDE.md` is a symlink to this file.

**This file describes THIS REPO'S CODE and nothing else.** Process rules live in
the envelope's `RULES.md` and must never be copied here.

## State

**ABANDONED: merged into fancy-chat-php 0.2.0** (`src/Prism/`, same class names; its
suite is in fancy-chat-php's `tests/Harness/`). Make no changes here; change
fancy-chat-php. The rest of this file describes 0.1.0.

The Harness adapter exists: `src/HarnessBackend.php`, an `AgentBackend` for
`fancy-chat-php` over `Session::streamSteps()` / `streamDecision()` (RunStep
contract `g282.1`). Not published. The ACP, bare `asStream()`, graph and memory
adapters of `.ai/plans/agent-chat-framework.md` section 6 are not built.

- `vendor/bin/pest` runs the suite; `php tests/sabotage.php` mutates each
  mapping clause and must report every one caught. A new clause gets a
  mutation in the same change.
- Tests use `tests/Support/FakeSession` (extends the real Harness `Session`,
  yields real `RunStep`s, dispatches real run events) for the mapping, and
  `tests/Integration/RealHarnessTest.php` for the real Harness with Prism's
  fake model.
- Harness run events are Laravel events dispatched mid-stream; the backend
  collects them per session key and yields them in place. They are not in the
  step stream.

## Invariants once code lands

1. **Map Prism's own types and stream events.** Import them from Prism; never
   redeclare a Prism shape here.
2. **This package depends on the core, never the reverse.** The core packages'
   packaging test runs against this package as its negative control, so it must
   keep a `prism*` dependency.
3. **A knowledge adapter is built only from an app-bound scope** (prism-graph's
   hard rule: every read is scoped; the user never picks the scope).
4. **No tool here decides an approval or answers a question.** Decisions are
   recorded by the host from a human actor, then handed to Harness approval mode.
