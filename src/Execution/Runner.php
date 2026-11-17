<?php

declare(strict_types=1);

/**
 * Runs one queued step delivery through preparation, action, and verification.
 *
 * The job carries a step ID and attempt token, not a live step object. Claim the
 * token first, restore inputs, then construct the user's class. Business code runs
 * outside workflow locks. Recheck authority between hooks because any of them can
 * cancel or replace the workflow. Saving the action result is a durable checkpoint:
 * redelivery after that point repeats verification, never the completed action.
 */

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
        /**
         * A duplicate or outdated job is a normal no-op. Only a successful claim starts
         * preparation and gives this delivery a running lease.
         */
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
            /**
             * Constructing the step can be necessary again for verification. Do not overwrite
             * the original action's argument snapshot once its result has been saved.
             */
            $recorder = $record->action_completed ? null : app(Signature::class)->recorder($record, $context);
            $step = $this->invoker->construct($record->class, $inputs, $context, $recorder);
            if (! $context->isCurrent()) {
                return;
            }
            if (! $record->action_completed) {
                /**
                 * Reserve any quota before before() or handle() runs. A wait postpones this
                 * attempt without charging its action-failure budget. Recheck ownership after
                 * custom policy calls because application code can revoke its own workflow.
                 */
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
                /**
                 * before() may skip, stop, retry, hand off children, or supply an accepted result.
                 * Only a null decision means call handle(); an accepted result still needs the
                 * same save-and-verify path as a normal return value.
                 */
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
                /**
                 * If construction failed there is no step object whose onException() can run.
                 * Use the configured input handler instead; it cannot invent success or children
                 * for an object that never existed. A Throttled exception has its own wait path.
                 */
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
            /**
             * Persist the result before checking whether it meets the business completion
             * condition. An encoding failure is terminal here: retrying verification cannot
             * repair a result that was never saved in the supported format.
             */
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
            /**
             * Verify the saved-and-restored value rather than the original in-memory return.
             * This checks what future workers will actually read. Verification errors spend
             * only the verification budget; they never re-enter handle() or onException().
             */
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
