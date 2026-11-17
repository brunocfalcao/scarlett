<?php

declare(strict_types=1);

/**
 * Expresses a lifecycle hook's choice in a form the engine can interpret.
 *
 * A state settles the step, a delay requests another delivery, and children hands
 * control to a newly declared child plan. producesResult distinguishes accepting a
 * value from a non-success outcome. These flags matter even for a null result.
 * Normally handle() returns ordinary data; Runner intercepts its child-plan decision
 * specifically. before() and exception hooks use the other decision factories.
 */

namespace Scarlett\Execution;

use Scarlett\Definition\ChildPlan;
use Scarlett\States\Completed;
use Scarlett\States\Failed;
use Scarlett\States\Skipped;
use Scarlett\States\Stopped;

/**
 * A hook outcome or runtime-child handoff interpreted by the engine.
 * Ordinary handle return values are results; only its child decision is intercepted.
 */
final readonly class Decision
{
    private function __construct(
        public ?string $state,
        public mixed $result = null,
        public ?int $delay = null,
        public bool $throttled = false,
        public bool $producesResult = false,
        public ?string $reason = null,
        public ?ChildPlan $children = null,
    ) {}

    public static function complete(mixed $result = null, string $state = Completed::class): self
    {
        return new self($state, $result, producesResult: true);
    }

    /** Return a complete child plan from handle(), before(), or onException(). */
    public static function children(array $nodes, mixed $result = null, ?string $resultFrom = null, string $state = Completed::class): self
    {
        return new self($state, $result, children: new ChildPlan($nodes, $resultFrom));
    }

    public static function skip(): self
    {
        return new self(Skipped::class);
    }

    public static function stop(?string $reason = null): self
    {
        return new self(Stopped::class, reason: $reason);
    }

    public static function fail(?string $reason = null): self
    {
        return new self(Failed::class, reason: $reason);
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
