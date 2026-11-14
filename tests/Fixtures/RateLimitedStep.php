<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Policies\RateLimit;
use Scarlett\Policies\Throttle;
use Scarlett\Step;
use Throwable;

/** Exercises real action retries separately from pre-action admission waits. */
class RateLimitedStep extends Step
{
    public static array $calls = [];

    public function __construct(private string $policy, private string $account, private bool $fail = false) {}

    public function throttle(): ?Throttle
    {
        return new RateLimit($this->policy, $this->account);
    }

    public function handle(Context $context): string
    {
        self::$calls[] = $context->stepId;
        if ($this->fail) {
            throw new \RuntimeException('API connection failed.');
        }

        return $this->account;
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        return Decision::retry(5);
    }
}
