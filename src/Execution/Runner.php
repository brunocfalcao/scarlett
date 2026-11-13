<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Scarlett\Engine;
use Scarlett\Exceptions\Throttled;
use Scarlett\Inputs\InputBindings;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\States\Completed;
use Throwable;

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
            $step = $this->invoker->construct($record->class, $inputs);
            if (! $context->isCurrent()) {
                return;
            }
            if ($record->action_completed) {
                $result = $this->codec->decode($record->result);
            } else {
                $delay = $step->throttle()?->delay($context);
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
                    $result = $this->invoker->handle($step, $inputs);
                }
                if (! $this->engine->saveAction($stepId, $attempt, $result, $state)) {
                    return;
                }
            }
        } catch (Throwable $exception) {
            if (! $context->isCurrent()) {
                return;
            }
            try {
                $decision = $exception instanceof Throttled ? Decision::throttle($exception->seconds) : ($step?->onException($exception, $context) ?? Decision::fail());
                if ($decision->producesResult) {
                    $result = $decision->result;
                    if (! $this->engine->saveAction($stepId, $attempt, $result, $decision->state)) {
                        return;
                    }
                } else {
                    $this->engine->decide($stepId, $attempt, $decision, $exception->getMessage());

                    return;
                }
            } catch (Throwable $handlerFailure) {
                $this->engine->decide($stepId, $attempt, Decision::fail(), $handlerFailure->getMessage());

                return;
            }
        }
        // Confirmation failures never re-enter the business action or its exception handler.
        if (! $context->isCurrent()) {
            return;
        }
        try {
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
