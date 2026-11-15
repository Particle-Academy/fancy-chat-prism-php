<?php

declare(strict_types=1);

/*
 * Break one mapping clause at a time; the suite must notice every one. A suite
 * written alongside its implementation passes on its first run, and a check
 * never seen to fail cannot be told apart from one that cannot. Each file is
 * restored in a `finally`. Exits 1 if any mutation survives, 2 if a mutation's
 * target text is missing (the list has drifted from the code).
 *
 *     php tests/sabotage.php
 */

$root = dirname(__DIR__);
$file = 'src/HarnessBackend.php';

/** @var list<array{0: string, 1: string, 2: string, 3: string}> [name, file, search, replace] */
$mutations = [
    // The version gate.
    ['any contract version accepted', $file, 'if ($version !== self::CONTRACT_VERSION) {', 'if (false) {'],
    ['a missing version accepted', $file, "\$version = is_array(\$step) ? (\$step['contractVersion'] ?? null) : null;", "\$version = is_array(\$step) ? (\$step['contractVersion'] ?? self::CONTRACT_VERSION) : null;"],
    ['wrong version keeps streaming', $file, "this adapter reads \"'.self::CONTRACT_VERSION.'\"');\n\n                        return;", "this adapter reads \"'.self::CONTRACT_VERSION.'\"');\n\n                        continue;"],
    ['version reason names no version', $file, "(is_string(\$version) ? '\"'.\$version.'\"' : 'none')", "'a different one'"],

    // Thinking.
    ['thinking never started', $file, "                yield self::event(\$step, 'thinking.started', []);\n", ''],
    ['every thinking delta starts a block', $file, "            if (\$st['thinking'] === null) {\n                \$st['thinking'] = \$step;", "            if (true) {\n                \$st['thinking'] = \$step;"],
    ['thinking never completed', $file, "            yield self::event(\$st['thinking'], 'thinking.completed', [], \$st['last']);\n", ''],
    ['thinking stays open into text', $file, "        if (\$type === RunStep::TEXT) {\n            yield from \$this->closeThinking(\$st);", "        if (\$type === RunStep::TEXT) {"],
    ['thinking delta loses its text', $file, "'thinking.delta', ['text' => self::str(\$step['delta'] ?? '')]", "'thinking.delta', ['text' => '']"],

    // Text.
    ['text opens no message', $file, "                yield self::event(\$step, 'message.created', ['message' => ['id' => \$messageId, 'role' => 'assistant', 'parts' => []]]);\n", ''],
    ['text message is the user\'s', $file, "'role' => 'assistant', 'parts' => []", "'role' => 'user', 'parts' => []"],
    ['text part never started', $file, "                yield self::event(\$step, 'part.started', ['messageId' => \$messageId, 'part' => ['id' => 'text', 'kind' => 'text', 'format' => 'markdown', 'text' => '']]);\n", ''],
    ['text part is plain', $file, "'format' => 'markdown', 'text' => ''", "'format' => 'plain', 'text' => ''"],
    ['text delta dropped', $file, "'partId' => \$st['text']['partId'], 'text' => self::str(\$step['delta'] ?? '')]", "'partId' => \$st['text']['partId'], 'text' => '']"],
    ['every text delta opens a message', $file, "            if (\$st['text'] === null) {\n                \$messageId", "            if (true) {\n                \$messageId"],
    ['text part never completed', $file, "            yield self::event(\$st['text']['step'], 'part.completed', ['messageId' => \$st['text']['messageId'], 'partId' => \$st['text']['partId']], \$st['last']);\n", ''],
    ['text after a tool joins the old message', $file, "        yield from \$this->closeAll(\$st);\n        switch (\$type) {", "        yield from \$this->closeThinking(\$st);\n        switch (\$type) {"],
    ['closing event carries the opening step', $file, "\$at = \$cause ?? \$step;", '$at = $step;'],

    // Tools.
    ['tool.called loses its args', $file, "                    'args' => \$args,\n                    'startedAt'", "                    'args' => [],\n                    'startedAt'"],
    ['tool.called loses startedAt', $file, "'startedAt' => \$step['startedAt'] ?? null,", "'startedAt' => null,"],
    ['tool.called loses its name', $file, "                    'name' => self::str(\$step['name'] ?? ''),\n                    'args'", "                    'name' => 'tool',\n                    'args'"],
    ['refusal read as failure', $file, "'outcome' => \$refused ? 'refusal' : (\$ok ? 'ok' : 'failure'),", "'outcome' => \$ok ? 'ok' : 'failure',"],
    ['a refused ok is ok', $file, "\$ok = (\$step['ok'] ?? null) === true && ! \$refused;", "\$ok = (\$step['ok'] ?? null) === true;"],
    ['failure read as ok', $file, "\$ok = (\$step['ok'] ?? null) === true && ! \$refused;", "\$ok = ! \$refused;"],
    ['failure output is the result', $file, "'output' => \$ok ? self::str(\$step['result'] ?? '') : self::str(\$error['message'] ?? ''),", "'output' => self::str(\$step['result'] ?? ''),"],
    ['ok output is the error', $file, "'output' => \$ok ? self::str(\$step['result'] ?? '') : self::str(\$error['message'] ?? ''),", "'output' => self::str(\$error['message'] ?? ''),"],
    ['duration always exact', $file, "'durationExact' => (\$step['durationExact'] ?? null) === true,", "'durationExact' => (\$step['durationExact'] ?? null) !== false,"],
    ['durationExact dropped', $file, "'durationExact' => (\$step['durationExact'] ?? null) === true,", ''],
    ['durationMs dropped', $file, "                    \$data['durationMs'] = \$step['durationMs'];\n", ''],
    ['error code dropped', $file, "                    \$data['errorCode'] = self::str(\$error['code'] ?? '');\n", ''],

    // Approvals.
    ['approval request ignored', $file, "                yield self::event(\$step, 'approval.requested', ['approval' => \$approval]);\n", ''],
    ['approval never parks the turn', $file, "            } elseif (\$st['approvals'] > 0) {", "            } elseif (false) {"],
    ['approval forgets its tool call', $file, "\$call = \$toolCallId !== null ? (\$st['calls'][\$toolCallId] ?? null) : null;", '$call = null;'],
    ['host wording ignored', $file, "                    (\$this->present)(\$request),\n", "                    self::english(\$request),\n"],
    ['approval not marked as Harness\'s', $file, "                        'origin' => self::ORIGIN,\n", ''],
    ['resume answers foreign approvals', $file, "if ((\$a['origin'] ?? null) !== self::ORIGIN || ! in_array(", "if (! in_array("],
    ['resume answers only the last approval', $file, "        if (\$chat instanceof FancyChat) {\n            foreach", "        if (false) {\n            foreach"],
    ['resume answers another turn\'s approval', $file, "if ((\$a['turnId'] ?? null) === \$input->turnId && is_string(", "if (is_string("],
    ['a rejection approves', $file, "\$a['decision'] === 'approve', is_string(", "\$a['decision'] !== 'edit', is_string("],
    ['the note is not the reason', $file, "is_string(\$a['note'] ?? null) ? \$a['note'] : null);", 'null);'],
    ['resume actor dropped', $file, "RunContext::metadata(\$input->turnId, self::actorOf(\$input->approval['decidedBy'] ?? null))", "RunContext::metadata(\$input->turnId)"],
    ['resume turn dropped', $file, "RunContext::metadata(\$input->turnId, self::actorOf(", "RunContext::metadata(null, self::actorOf("],
    ['resume with nothing to decide still resumes', $file, "            if (\$decisions === []) {\n                yield self::fail('harness.no-decisions');\n\n                return;\n            }", ''],
    ['decision_recorded renamed', $file, "self::EXTENSION.'.decision-recorded', [", "self::EXTENSION.'.decided', ["],
    ['decision verdict dropped', $file, "'verdict' => \$step['verdict'] ?? null,", "'verdict' => null,"],

    // Errors and unknowns.
    ['error step ignored', $file, "\$st['failure'] ??= 'harness.error: '", "\$unused = 'harness.error: '"],
    ['error does not fail the turn', $file, "            if (\$st['failure'] !== null) {\n                yield self::fail(\$st['failure']);", "            if (false) {\n                yield self::fail(\$st['failure']);"],
    ['unknown step type ignored', $file, "        yield self::fail('harness.step-type: '.(is_string(\$type) ? \$type : 'none'));\n\n        return false;", "        return true;"],
    ['open parts left open on a throw', $file, "                yield from \$this->closeAll(\$st);\n                yield from \$this->flushRuns(\$key);\n\n                throw \$e;", "                throw \$e;"],

    // Run events and correlation.
    ['run events never collected', $file, "                \$this->runEvents[\$event->sessionKey][] = \$event;", ';'],
    ['other sessions\' runs collected', $file, "if (array_key_exists(\$event->sessionKey, \$this->runEvents)) {", "if (\$this->runEvents !== []) {\n                \$this->runEvents[array_key_first(\$this->runEvents)][] = \$event;\n            } elseif (false) {"],
    ['run events only flushed at the end', $file, "                foreach (\$open() as \$raw) {\n                    yield from \$this->flushRuns(\$key);", "                foreach (\$open() as \$raw) {"],
    ['parentRunId dropped from runs', $file, "\$envelope = ['runId' => \$event->runId, 'parentRunId' => \$event->parentRunId,", "\$envelope = ['runId' => \$event->runId, 'parentRunId' => null,"],
    ['root run has no rootRunId', $file, "'rootRunId' => \$event->rootRunId ?? \$event->runId];", "'rootRunId' => \$event->rootRunId];"],
    ['resumed run reads as started', $file, "\$event instanceof RunResumed => ['type' => self::EXTENSION.'.run-resumed',", "\$event instanceof RunResumed => ['type' => 'run.started',"],
    ['run.finished loses its reason', $file, "'data' => ['finishReason' => \$event->finishReason,", "'data' => ['finishReason' => null,"],
    ['step runId not on the envelope', $file, "            'type' => \$type,\n            'runId' => \$runId,", "            'type' => \$type,\n            'runId' => null,"],
    ['step parentRunId dropped', $file, "'parentRunId' => \$step['parentRunId'] ?? null,", "'parentRunId' => null,"],
    ['step rootRunId dropped', $file, "'rootRunId' => \$step['rootRunId'] ?? \$runId,", "'rootRunId' => \$runId,"],
    ['harness seq dropped', $file, "'seq' => \$at['seq'] ?? null,", "'seq' => null,"],
    ['harness turnId dropped', $file, "'turnId' => \$at['turnId'] ?? null,", "'turnId' => null,"],
    ['chat turn not passed to Harness', $file, "\$context = RunContext::metadata(\$input->turnId);", '$context = null;'],

    // Prompt and cancel.
    ['empty prompt sent to Harness', $file, "            if (trim(\$prompt) === '') {", '            if (false) {'],
    ['prompt keeps only the last text', $file, "                \$texts[] = \$part['text'];", "                \$texts = [\$part['text']];"],
    ['cancel claimed without Harness support', $file, "return method_exists(\$this->sessionClass, 'cancel');", 'return true;'],
    ['cancel outcomes not checked', $file, "return in_array(\$value, CancelOutcome::ALL, true) ? \$value : CancelOutcome::UNCERTAIN;", 'return (string) $value;'],
    ['enum outcome not read by value', $file, "\$value = \$outcome instanceof BackedEnum ? \$outcome->value : \$outcome;", '$value = $outcome;'],
    ['no run reads as ok', $file, "                return CancelOutcome::NO_TURN;", "                return CancelOutcome::OK;"],
    ['a throwing cancel reads as ok', $file, "            self::report(\$e);\n\n            return CancelOutcome::UNCERTAIN;", "            self::report(\$e);\n\n            return CancelOutcome::OK;"],
    ['cancels the wrong run', $file, "\$outcome = \$session->cancel(\$run['id']);", "\$outcome = \$session->cancel('run_other');"],
];

$failed = [];
$missing = [];
foreach ($mutations as [$name, $target, $search, $replace]) {
    $path = $root.'/'.$target;
    $original = (string) file_get_contents($path);
    if (substr_count($original, $search) !== 1) {
        echo "MISSING  {$name}: pattern found ".substr_count($original, $search)." times in {$target}\n";
        $missing[] = $name;

        continue;
    }
    try {
        file_put_contents($path, str_replace($search, $replace, $original));
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/pest').' --bail 2>&1';
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0) {
            echo "SURVIVED {$name}\n";
            $failed[] = $name;
        } else {
            echo "caught   {$name}\n";
        }
    } finally {
        file_put_contents($path, $original);
    }
}

$total = count($mutations);
$caught = $total - count($failed) - count($missing);
echo "sabotage: {$total} mutations, {$caught} caught, ".count($failed).' survived, '.count($missing)." missing\n";
exit($missing !== [] ? 2 : ($failed !== [] ? 1 : 0));
