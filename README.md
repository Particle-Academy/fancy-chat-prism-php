# fancy-chat-prism-php

**OPTIONAL** PHP Prism adapters for the Fancy agent-chat framework (council
change CC-fancy-0042). **Not started; nothing here is published.**

`particle-academy/fancy-chat-php` serves a provider-neutral contract and has no
Prism dependency. This package maps Prism onto it, for hosts that run Prism:

- prism-harness `RunStarted` / `RunFinished` / `RunFailed` onto `run.*`, keeping
  `parentRunId` and `rootRunId`;
- Prism core `asStream()` events (`StepStart`, `Thinking*`, `Text*`, `ToolCall`,
  `ToolResult`, `ToolApprovalRequest`, `Citation`, `Artifact`, `Error`) onto parts,
  activity and approval records, with decisions recorded into Harness approval
  mode so the run resumes;
- prism-graph and prism-memory, as optional knowledge and memory adapters.

It uses Prism's own types and stream events; nothing Prism already does is
rewritten here.

Design: `.ai/plans/agent-chat-framework.md` in the Fancy envelope.
