<?php

declare(strict_types=1);

namespace Scarlett\Failures;

use Scarlett\Execution\ConnectionRelease;
use Scarlett\Execution\Context;
use Scarlett\Execution\Failure;
use Scarlett\Execution\Invoker;
use Scarlett\Inputs\InputBindings;
use Throwable;

/**
 * Runs a claimed terminal hook with restored inputs or the static metadata fallback.
 * Hook errors retry this handler; they never call the business exception classifier.
 */
final class Runner
{
    public function __construct(private Coordinator $coordinator, private InputBindings $bindings, private Invoker $invoker) {}

    public function run(string $id, string $attempt): void
    {
        try {
            $this->execute($id, $attempt);
        } finally {
            app(ConnectionRelease::class)->release();
        }
    }

    private function execute(string $id, string $attempt): void
    {
        $record = $this->coordinator->claim($id, $attempt);
        if ($record === null) {
            return;
        }
        $context = new Context($record->workflow_id, $record->step_id, $attempt, $record->failures, failureId: $id);
        try {
            $step = null;
            $preparationError = null;
            try {
                $inputs = $this->bindings->resolve($record->step);
                if (! $context->isCurrent()) {
                    return;
                }
                $step = $this->invoker->construct($record->step->class, $inputs, $context);
            } catch (Throwable $exception) {
                $preparationError = $exception->getMessage();
            }
            if (! $context->isCurrent()) {
                return;
            }
            $failure = Failure::fromRecord($record, $preparationError);
            $context->releaseDatabaseConnection();
            if ($step !== null) {
                $step->onFailure($context, $failure);
            } else {
                $record->step->class::onFailureWithoutInstance($context, $failure);
            }
            $this->coordinator->complete($id, $attempt);
        } catch (Throwable $exception) {
            $this->coordinator->reject($id, $attempt, $exception->getMessage());
        }
    }
}
