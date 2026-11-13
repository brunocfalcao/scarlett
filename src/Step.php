<?php

declare(strict_types=1);

namespace Scarlett;

use Scarlett\Definition\ExecutionOptions;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;
use Scarlett\Policies\Throttle;

/** Declare a public handle method with your own typed parameters and return type. */
abstract class Step
{
    public static function executionOptions(): ExecutionOptions
    {
        return new ExecutionOptions;
    }

    public function onFailure(Context $context, Failure $failure): void
    {
        static::onFailureWithoutInstance($context, $failure);
    }

    /** Runs without constructing the step when its saved inputs cannot be restored. */
    public static function onFailureWithoutInstance(Context $context, Failure $failure): void
    {
        throw new \RuntimeException('Cannot restore the step for failure handling. Implement onFailureWithoutInstance using persisted identifiers.');
    }

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
