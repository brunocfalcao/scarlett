<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Scarlett\States\Completed;
use Scarlett\States\Failed;
use Scarlett\States\Skipped;
use Scarlett\States\Stopped;

final readonly class Decision
{
    private function __construct(
        public ?string $state,
        public mixed $result = null,
        public ?int $delay = null,
        public bool $throttled = false,
        public bool $producesResult = false,
    ) {}

    public static function complete(mixed $result = null, string $state = Completed::class): self
    {
        return new self($state, $result, producesResult: true);
    }

    public static function skip(): self
    {
        return new self(Skipped::class);
    }

    public static function stop(): self
    {
        return new self(Stopped::class);
    }

    public static function fail(): self
    {
        return new self(Failed::class);
    }

    public static function retry(?int $seconds = null): self
    {
        if ($seconds !== null && $seconds < 0) {
            throw new \InvalidArgumentException('Retry delay cannot be negative.');
        }

        return new self(null, delay: $seconds);
    }

    public static function throttle(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Throttle delay cannot be negative.');
        }

        return new self(null, delay: $seconds, throttled: true);
    }
}
