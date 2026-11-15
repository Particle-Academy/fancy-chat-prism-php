# AGENTS.md — fancy-chat-prism-php

`CLAUDE.md` is a symlink to this file.

**This file describes THIS REPO'S CODE and nothing else.** Process rules live in
the envelope's `RULES.md` and must never be copied here.

## State

Not started. Planned for phases P2 to P4 of `.ai/plans/agent-chat-framework.md`.
There is no `composer.json` yet, so there is nothing to test or publish.

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
