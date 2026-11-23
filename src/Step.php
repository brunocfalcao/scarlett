<?php

declare(strict_types=1);

/**
 * The base class application authors extend to implement business work.
 *
 * Declare a public handle() with the typed parameters that work needs. Scarlett
 * prepares named inputs, calls before(), runs handle() when permitted, saves the
 * result, and calls verify(). A saved action is not repeated for verification retries.
 *
 * onException() classifies a failure while the action path is still active, including a
 * passing database error while Scarlett saves the result or records a child plan.
 * onFailure() is different: it is durable follow-up work after terminal failure,
 * with its own retries. Constructors and hooks may be invoked again after worker
 * loss, so external effects must tolerate repetition and writes should use Context.
 */

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

    /**
     * Classify errors while the action path is alive, including a deadlock or dropped
     * connection while Scarlett saves the result or records a child plan. Worker loss
     * uses durable failure handling.
     */
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
