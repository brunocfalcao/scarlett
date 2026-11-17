<?php

declare(strict_types=1);

/**
 * Defines how a reusable policy translates an exception into an engine decision.
 *
 * The decision may request retry, throttling, or a terminal outcome. Context gives
 * the policy the current attempt's ownership and budget information. Input preparation
 * uses this contract because there may be no constructed step to receive onException().
 */

namespace Scarlett\Policies;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;

/**
 * Reusable exception classification contract for consumer policies and input preparation.
 */
abstract class ExceptionHandler
{
    abstract public function decide(\Throwable $exception, Context $context): Decision;
}
