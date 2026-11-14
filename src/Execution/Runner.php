<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Scarlett\Engine;
use Scarlett\Exceptions\Throttled;
use Scarlett\Inputs\InputBindings;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\States\Completed;
use Throwable;

/**
 * Executes one claimed attempt, separating action persistence from verification.
 * Once action_completed is stored, redelivery must never run that action again.
 */
final class Runner
{
    public function __construct(
        private Engine $engine,
        private InputBindings $bindings,
        private PayloadCodec $codec,
        private Invoker $invoker,
    ) {}

    public function run(string $stepId, string $attempt): void
    {
        try {
            $this->execute($stepId, $attempt);
        } finally {
            app(ConnectionRelease::class)->release();
        }
    }

    private function execute(string $stepId, string $attempt): void
    {
        $record = $this->engine->claim($stepId, $attempt);
        if ($record === null) {
            return;
        }
        $context = new Context($record->workflow_id, $stepId, $attempt, $record->failures);
        $step = null;
        try {
            $inputs = $this->bindings->resolve($record);
            if (! $context->isCurrent()) {
                return;
            }
            $recorder = $record->action_completed ? null : app(Signature::class)->recorder($record, $context);
            $step = $this->invoker->construct($record->class, $inputs, $context, $recorder);
            if (! $context->isCurrent()) {
                return;
            }
            if (! $record->action_completed) {
                $throttle = $step->throttle();
                if (! $context->isCurrent()) {
                    return;
                }
                $delay = $throttle?->delay($context);
                if (! $context->isCurrent()) {
                    return;
                }
                if ($delay !== null) {
                    $this->engine->decide($stepId, $attempt, Decision::throttle($delay));

                    return;
                }
                $decision = $step->before($context);
                if (! $context->isCurrent()) {
                    return;
                }
                if ($decision !== null && ! $decision->producesResult) {
                    $this->engine->decide($stepId, $attempt, $decision);

                    return;
                }
                $result = $decision?->result;
                $state = $decision?->state ?? Completed::class;
                if ($decision === null) {
                    $result = $this->invoker->handle($step, $inputs, $context, $recorder);
                    if ($result instanceof Decision && $result->children !== null) {
                        $this->engine->decide($stepId, $attempt, $result);

                        return;
                    }
                }
            }
        } catch (Throwable $exception) {
            if (! $context->isCurrent()) {
                return;
            }
            try {
                $decision = match (true) {
                    $exception instanceof Throttled => Decision::throttle($exception->seconds),
                    $step !== null => $step->onException($exception, $context),
                    default => app(config('scarlett.input_exception_handler'))->decide($exception, $context),
                };
                if ($step === null && ($decision->producesResult || $decision->children !== null)) {
                    throw new \InvalidArgumentException('Input exception handlers cannot complete or expand an unconstructed step.');
                }
                if ($decision->producesResult) {
                    $result = $decision->result;
                    $state = $decision->state;
                } else {
                    $this->engine->decide($stepId, $attempt, $decision, $exception->getMessage(), verification: $record->action_completed, cause: $step === null ? FailureCause::InputResolution : FailureCause::Decided);

                    return;
                }
            } catch (Throwable $handlerFailure) {
                $this->engine->decide($stepId, $attempt, Decision::fail(), $handlerFailure->getMessage(), cause: FailureCause::HandlerCrashed);

                return;
            }
        }
        if (! $record->action_completed) {
            try {
                if (! $this->engine->saveAction($stepId, $attempt, $result, $state)) {
                    return;
                }
            } catch (Throwable $persistenceFailure) {
                $this->engine->decide($stepId, $attempt, Decision::fail(), $persistenceFailure->getMessage(), cause: FailureCause::ResultPersistence);

                return;
            }
        }
        // Confirmation failures never re-enter the business action or its exception handler.
        if (! $context->isCurrent()) {
            return;
        }
        try {
            $result = $this->codec->decode($record->refresh()->result);
            if (! $context->isCurrent()) {
                return;
            }
            $context->releaseDatabaseConnection();
            $verified = $step->verify($context, $result);
        } catch (Throwable $exception) {
            $this->engine->decide($stepId, $attempt, Decision::retry(), $exception->getMessage(), verification: true);

            return;
        }
        if (! $verified) {
            $this->engine->decide($stepId, $attempt, Decision::retry(), 'Completion verification did not pass.', verification: true);

            return;
        }
        $this->engine->finish($stepId, $attempt);
    }
}
