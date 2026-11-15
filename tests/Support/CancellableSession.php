<?php

declare(strict_types=1);

namespace FancyChat\Prism\Tests\Support;

/**
 * A session with the G-285 durable cancel, which Harness 0.26 does not ship
 * yet (`Session::cancel(string $runId)` returning ok | not-running | no-turn |
 * unsupported | refused). Stands in for the release that adds it.
 */
final class CancellableSession extends FakeSession
{
    /** @var list<string> */
    public array $cancelled = [];

    public mixed $outcome = 'ok';

    public function cancel(string $runId): mixed
    {
        $this->cancelled[] = $runId;

        return $this->outcome;
    }
}
