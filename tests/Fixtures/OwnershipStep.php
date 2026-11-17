<?php

declare(strict_types=1);

/**
 * Selects a throttle that revokes this very attempt while being evaluated.
 * The precheck counter and unreachable handle() expose any missing authority recheck
 * between custom policy execution and subsequent business lifecycle calls.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Policies\Throttle;
use Scarlett\Step;

final class OwnershipStep extends Step
{
    public static int $prechecks = 0;

    public function throttle(): ?Throttle
    {
        return new RevokingThrottle;
    }

    public function before(Context $context): ?Decision
    {
        self::$prechecks++;

        return null;
    }

    public function handle(): never
    {
        throw new \LogicException('Obsolete work must not execute.');
    }
}
