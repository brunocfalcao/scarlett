<?php

declare(strict_types=1);

/**
 * A configurable business step used to make lifecycle outcomes observable.
 * Constructor and action logs reveal early construction or duplicate execution. Modes
 * choose skip, stop, failure, exceptions, throttling, or verification rejection, letting
 * tests vary one lifecycle condition while retaining the same graph and inputs.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Exceptions\Throttled;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;

class ControlledStep extends Step
{
    public static array $actions = [];

    public static array $constructed = [];

    public function __construct(public string $name, public string $mode = 'success', public mixed $value = null)
    {
        self::$constructed[] = [$name, $value];
    }

    public function before(Context $context): ?Decision
    {
        /**
         * Precheck terminal modes deliberately avoid handle(). Other modes reach the
         * action so tests can separate a decision before work from a failure during work.
         */
        return match ($this->mode) {
            'skip' => Decision::skip(), 'stop' => Decision::stop(), 'fail' => Decision::fail(), default => null,
        };
    }

    public function handle(): mixed
    {
        self::$actions[$this->name] = (self::$actions[$this->name] ?? 0) + 1;
        if ($this->mode === 'throw') {
            throw new \RuntimeException('test failure');
        }
        if ($this->mode === 'throttle') {
            throw new Throttled(10);
        }

        return $this->value;
    }

    public function verify(Context $context, mixed $result): bool
    {
        return $this->mode !== 'verify';
    }
}
