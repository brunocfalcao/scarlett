<?php

declare(strict_types=1);

/**
 * Defines an optional wait before an action is allowed to start.
 *
 * Return null when capacity is available or a non-negative delay in seconds when it
 * is not. Runner evaluates this before before() and handle(). Waiting does not spend
 * the action-failure budget. RateLimit implements a shared database-backed policy;
 * applications can supply another policy by extending this contract.
 */

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
