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
    /** Return declarative defaults; admission calls this without constructing a step. */
    public static function executionOptions(): ExecutionOptions
    {
        return new ExecutionOptions;
    }

    /** Opt in to durable terminal effects; retries require idempotent consumer writes. */
    public function onFailure(Context $context, Failure $failure): void
    {
        static::onFailureWithoutInstance($context, $failure);
    }

    /** Runs without constructing the step when its saved inputs cannot be restored. */
    public static function onFailureWithoutInstance(Context $context, Failure $failure): void
    {
        throw new \RuntimeException('Cannot restore the step for failure handling. Implement onFailureWithoutInstance using persisted identifiers.');
    }

    /** Return null to run handle; a decision may defer, settle or hand off children. */
    public function before(Context $context): ?Decision
    {
        return null;
    }

    /** Inspect the decoded saved result; retries repeat verification, not the action. */
    public function verify(Context $context, mixed $result): bool
    {
        return true;
    }

    /** Classify errors while the action path is alive; worker loss uses durable failure handling. */
    public function onException(\Throwable $exception, Context $context): Decision
    {
        return Decision::retry();
    }

    /** Return the consumer rate-limit policy evaluated before the action precheck. */
    public function throttle(): ?Throttle
    {
        return null;
    }
}
