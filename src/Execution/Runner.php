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

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use PDOException;
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
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

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
     * onException() or replay handle(). Each phase then uses its own failure boundary. The one
     * exception is a passing database error while saving the action's result or handing off
     * its child plan: it is classified like an action error, so the step's own rules can retry.
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
        $exception = null;
        $saved = false;
        $engineWrite = false;
        try {
            $decision = $this->act($record, $context, $step);
        } catch (Throwable $exception) {
            $decision = null;
        }
        /**
         * A child plan or result from the business action is an action result: a rejected or
         * unsaved one settles the step once. A passing database error says nothing about that
         * outcome, so it is classified like an action error and the step's own rules decide on
         * a retry.
         */
        if ($exception === null && $decision?->children !== null) {
            $exception = $this->handOff($stepId, $attempt, $decision, $rejectedPlan, returnPassing: true);
            if ($exception === null) {
                return;
            }
            $engineWrite = true;
        } elseif ($exception === null && $decision?->producesResult && ! $record->action_completed) {
            $saved = $this->persist($stepId, $attempt, $decision, returnPassing: true, passing: $exception);
            // A save can commit before its connection drops; then only verification remains.
            if ($exception !== null && $record->refresh()->action_completed) {
                $exception = null;
                $saved = true;
            }
            if (! $saved && $exception === null) {
                return;
            }
            $engineWrite = $exception !== null;
        }
        if ($exception !== null) {
            if (! $context->isCurrent()) {
                return;
            }
            try {
                $decision = $this->classify($exception, $step, $context);
            } catch (Throwable $handlerFailure) {
                $this->engine->decide($stepId, $attempt, Decision::fail(), $handlerFailure->getMessage(), cause: FailureCause::HandlerCrashed);

                return;
            }
            /**
             * Read the message only after classification: onException() may replace text that
             * quotes private content, and the engine stores this text as the step's error. An
             * engine write's own database error is stored without its message (StoredError).
             */
            $error = $engineWrite ? StoredError::describe($exception) : $exception->getMessage();
            $verification = $record->action_completed;
            $cause = $step === null ? FailureCause::InputResolution : FailureCause::Decided;
            $rejectedPlan = FailureCause::HandlerCrashed;
        }
        if ($decision === null) {
            return;
        }
        if (! $decision->producesResult) {
            $this->settle($stepId, $attempt, $decision, $error, verification: $verification, cause: $cause, rejectedPlan: $rejectedPlan);

            return;
        }
        if (! $record->action_completed && ! $saved && ! $this->persist($stepId, $attempt, $decision)) {
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
     * repair a result that was never saved in the supported format. With $returnPassing, a
     * passing database error is handed back unsettled in $passing, as handOff() does.
     */
    private function persist(string $stepId, string $attempt, Decision $decision, bool $returnPassing = false, ?Throwable &$passing = null): bool
    {
        try {
            return $this->engine->saveAction($stepId, $attempt, $decision->result, $decision->state ?? Completed::class);
        } catch (Throwable $persistenceFailure) {
            if ($returnPassing && $this->isPassingDatabaseError($persistenceFailure)) {
                $passing = $persistenceFailure;

                return false;
            }
            $this->engine->decide($stepId, $attempt, Decision::fail(), StoredError::describe($persistenceFailure), cause: FailureCause::ResultPersistence);

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
     * Settles a decision that needs no result save. Only a runtime child plan is an action
     * result, so only it turns a rejected or unsaved write into a terminal failure. Other
     * engine errors are left to the lease so a transient database fault is retried by
     * recovery rather than classified. Plans from handle() or before() are handed off in
     * execute(); a plan here came from onException() and keeps the handler-crash cause, so
     * failure hooks see which code produced the unusable outcome.
     */
    private function settle(string $stepId, string $attempt, Decision $decision, ?string $error = null, bool $verification = false, FailureCause $cause = FailureCause::Decided, FailureCause $rejectedPlan = FailureCause::ResultPersistence): void
    {
        if ($decision->children === null) {
            $this->engine->decide($stepId, $attempt, $decision, $error, $verification, $cause);

            return;
        }
        $this->handOff($stepId, $attempt, $decision, $rejectedPlan);
    }

    /**
     * Records a runtime child plan. A rejected or unsaved plan settles the step with the
     * $rejectedPlan cause. With $returnPassing, a passing database error is returned unsettled
     * instead, so the caller can classify it as v2.3.4 did; otherwise the result is null.
     */
    private function handOff(string $stepId, string $attempt, Decision $decision, FailureCause $rejectedPlan, bool $returnPassing = false): ?Throwable
    {
        try {
            $this->engine->decide($stepId, $attempt, $decision);
        } catch (Throwable $persistenceFailure) {
            if ($returnPassing && $this->isPassingDatabaseError($persistenceFailure)) {
                return $persistenceFailure;
            }
            $this->engine->decide($stepId, $attempt, Decision::fail(), StoredError::describe($persistenceFailure), cause: $rejectedPlan);
        }

        return null;
    }

    /**
     * A deadlock, serialization conflict or dropped connection: the database refused the
     * write for now, not the plan. Laravel's detectors decide, plus the SQLSTATE that PDO
     * keeps in errorInfo when the exception code does not carry it. The detectors match
     * message text, so only database exceptions are asked: a plan validation error whose
     * text happens to mention a deadlock stays a rejection.
     */
    private function isPassingDatabaseError(Throwable $exception): bool
    {
        return $exception instanceof PDOException && (
            $this->causedByConcurrencyError($exception)
            || $this->causedByLostConnection($exception)
            || ($exception->errorInfo[0] ?? null) === '40001'
        );
    }
}
