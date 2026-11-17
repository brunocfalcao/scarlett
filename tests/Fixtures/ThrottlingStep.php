<?php

declare(strict_types=1);

/**
 * Produces the same requested wait through four entry paths: a throttle policy,
 * a before() decision, a Throttled exception, or an exception handler decision.
 * The shared blocked flag releases work later; the action counter proves waiting
 * does not accidentally execute or consume the normal action failure budget.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Exceptions\Throttled;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Policies\Throttle;
use Scarlett\Step;
use Throwable;

class ThrottlingStep extends Step
{
    public static bool $blocked = true;

    public static int $actions = 0;

    public function __construct(private string $source, private int $seconds = 0) {}

    public function throttle(): ?Throttle
    {
        return $this->source === 'policy' ? new class($this->seconds) extends Throttle
        {
            public function __construct(private int $seconds) {}

            public function delay(Context $context): ?int
            {
                return ThrottlingStep::$blocked ? $this->seconds : null;
            }
        } : null;
    }

    public function before(Context $context): ?Decision
    {
        return $this->source === 'precheck' && self::$blocked ? Decision::throttle($this->seconds) : null;
    }

    public function handle(): string
    {
        /**
         * Do not increment the successful-action counter for any blocked path. Tests
         * compare it with failure counters to distinguish attempted waits from real work.
         */
        if (self::$blocked && $this->source === 'exception') {
            throw new Throttled($this->seconds);
        }
        if (self::$blocked && $this->source === 'handler') {
            throw new \RuntimeException('Provider requested a wait.');
        }
        self::$actions++;

        return 'ready';
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        return Decision::throttle($this->seconds);
    }
}
