<?php

declare(strict_types=1);

namespace Scarlett;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Policies\Throttle;

/** Declare a public handle method with your own typed parameters and return type. */
abstract class Step
{
    public function before(Context $context): ?Decision
    {
        return null;
    }

    public function verify(Context $context, mixed $result): bool
    {
        return true;
    }

    public function onException(\Throwable $exception, Context $context): Decision
    {
        return Decision::retry();
    }

    public function throttle(): ?Throttle
    {
        return null;
    }
}
