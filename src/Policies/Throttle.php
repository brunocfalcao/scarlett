<?php

declare(strict_types=1);

namespace Scarlett\Policies;

use Scarlett\Execution\Context;

/**
 * Consumer rate-limit policy; waits defer work without consuming its failure budget.
 */
abstract class Throttle
{
    /** Return null to proceed, otherwise the retry delay in seconds. */
    abstract public function delay(Context $context): ?int;
}
