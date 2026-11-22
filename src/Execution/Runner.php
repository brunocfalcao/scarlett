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
use Scarlett\Models\StepRecord;
use Scarlett\States\Completed;
use Scarlett\Step;
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

    /**
     * Business code (act, classify) never calls the engine inside its try block. Engine
     * writes for the resulting decision happen afterwards, so an engine error cannot re-enter
     * onException() or replay handle(). Each phase then uses its own failure boundary.
     */
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
        $error = null;
        $verification = false;
        $cause = FailureCause::Decided;
        $rejectedPlan = FailureCause::ResultPersistence;
        try {
            $decision = $this->act($record, $context, $step);
        } catch (Throwable $exception) {
            if (! $context->isCurrent()) {
                return;
            }
            $error = $exception->getMessage();
            $verification = $record->action_completed;
            $cause = $step === null ? FailureCause::InputResolution : FailureCause::Decided;
            $rejectedPlan = FailureCause::HandlerCrashed;
            try {
                $decision = $this->classify($exception, $step, $context);
            } catch (Throwable $handlerFailure) {
                $this->engine->decide($stepId, $attempt, Decision::fail(), $handlerFailure->getMessage(), cause: FailureCause::HandlerCrashed);

                return;
            }
        }
        if ($decision === null) {
            return;
        }
        if (! $decision->producesResult) {
            $this->settle($stepId, $attempt, $decision, $error, verification: $verification, cause: $cause, rejectedPlan: $rejectedPlan);

            return;
        }
        if (! $record->action_completed && ! $this->persist($stepId, $attempt, $decision)) {
            return;
        }
        // Confirmation failures never re-enter the business action or its exception handler.
        if (! $context->isCurrent()) {
            return;
        }
        $this->verify($stepId, $attempt, $context, $record, $step);
    }

    /**
     * The business section: restore inputs, construct the step, then run throttle, before and
     * handle. Returns the decision to settle, or null when ownership was lost. Exceptions are
     * left to execute() so that classification and engine writes stay outside this method.
     */
    private function act(StepRecord $record, Context $context, ?Step &$step): ?Decision
    {
        $inputs = $this->bindings->resolve($record);
        if (! $context->isCurrent()) {
            return null;
        }
        /**
         * Constructing the step can be necessary again for verification. Do not overwrite
         * the original action's argument snapshot once its result has been saved.
         */
        $recorder = $record->action_completed ? null : app(Signature::class)->recorder($record, $context);
        $step = $this->invoker->construct($record->class, $inputs, $context, $recorder);
        if (! $context->isCurrent()) {
            return null;
        }
        if ($record->action_completed) {
            // The saved result is already durable; only verification remains.
            return Decision::complete();
        }
        /**
         * Reserve any quota before before() or handle() runs. A wait postpones this
         * attempt without charging its action-failure budget. Recheck ownership after
         * custom policy calls because application code can revoke its own workflow.
         */
        $throttle = $step->throttle();
        if (! $context->isCurrent()) {
            return null;
        }
        $delay = $throttle?->delay($context);
        if (! $context->isCurrent()) {
            return null;
        }
        if ($delay !== null) {
            return Decision::throttle($delay);
        }
        /**
         * before() may skip, stop, retry, hand off children, or supply an accepted result.
         * Only a null decision means call handle(); an accepted result still needs the
         * same save-and-verify path as a normal return value.
         */
        $decision = $step->before($context);
        if (! $context->isCurrent()) {
            return null;
        }
        if ($decision !== null) {
            return $decision;
        }
        $result = $this->invoker->handle($step, $inputs, $context, $recorder);
        if ($result instanceof Decision && $result->children !== null) {
            return $result;
        }

        return Decision::complete($result);
    }

    /**
     * Maps a failure to a decision. If construction failed there is no step object whose
     * onException() can run. Use the configured input handler instead; it cannot invent
     * success or children for an object that never existed. A Throttled exception has its
     * own wait path. Classification performs no engine writes.
     */
    private function classify(Throwable $exception, ?Step $step, Context $context): Decision
    {
        $decision = match (true) {
            $exception instanceof Throttled => Decision::throttle($exception->seconds),
            $step !== null => $step->onException($exception, $context),
            default => app(config('scarlett.input_exception_handler'))->decide($exception, $context),
        };
        if ($step === null && ($decision->producesResult || $decision->children !== null)) {
            throw new \InvalidArgumentException('Input exception handlers cannot complete or expand an unconstructed step.');
        }

        return $decision;
    }

    /**
     * Persist the result before checking whether it meets the business completion
     * condition. An encoding failure is terminal here: retrying verification cannot
     * repair a result that was never saved in the supported format.
     */
    private function persist(string $stepId, string $attempt, Decision $decision): bool
    {
        try {
            return $this->engine->saveAction($stepId, $attempt, $decision->result, $decision->state ?? Completed::class);
        } catch (Throwable $persistenceFailure) {
            $this->engine->decide($stepId, $attempt, Decision::fail(), $persistenceFailure->getMessage(), cause: FailureCause::ResultPersistence);

            return false;
        }
    }

    /**
     * Verify the saved-and-restored value rather than the original in-memory return.
     * This checks what future workers will actually read. Verification errors spend
     * only the verification budget; they never re-enter handle() or onException().
     */
    private function verify(string $stepId, string $attempt, Context $context, StepRecord $record, ?Step $step): void
    {
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

    /**
     * Only a runtime child plan is an action result, so only it turns a rejected or unsaved
     * write into a terminal failure. Other engine errors are left to the lease so a transient
     * database fault is retried by recovery rather than classified. A plan from handle() or
     * before() fails as result persistence; a plan returned by onException() keeps the
     * handler-crash cause, so failure hooks see which code produced the unusable outcome.
     */
    private function settle(string $stepId, string $attempt, Decision $decision, ?string $error = null, bool $verification = false, FailureCause $cause = FailureCause::Decided, FailureCause $rejectedPlan = FailureCause::ResultPersistence): void
    {
        if ($decision->children === null) {
            $this->engine->decide($stepId, $attempt, $decision, $error, $verification, $cause);

            return;
        }
        try {
            $this->engine->decide($stepId, $attempt, $decision);
        } catch (Throwable $persistenceFailure) {
            $this->engine->decide($stepId, $attempt, Decision::fail(), $persistenceFailure->getMessage(), cause: $rejectedPlan);
        }
    }
}
