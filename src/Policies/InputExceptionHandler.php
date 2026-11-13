<?php

declare(strict_types=1);

namespace Scarlett\Policies;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Throwable;

/** Classifies failures before a step instance exists, using the consumer's terminal exception registry. */
class InputExceptionHandler extends ExceptionHandler
{
    public function decide(Throwable $exception, Context $context): Decision
    {
        foreach (config('scarlett.input_terminal_exceptions', []) as $terminal) {
            if ($exception instanceof $terminal) {
                return Decision::fail();
            }
        }

        return Decision::retry();
    }
}
