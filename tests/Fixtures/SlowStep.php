<?php

declare(strict_types=1);

/**
 * Keeps a real queue worker busy for a caller-selected number of seconds.
 * Used to distinguish in-progress execution and worker timeouts from a job that
 * finishes immediately; no business effects are needed for that observation.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class SlowStep extends Step
{
    public function handle(int $seconds): void
    {
        sleep($seconds);
    }
}
