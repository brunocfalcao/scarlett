<?php

declare(strict_types=1);

/**
 * Recursively discovers one child until depth reaches zero. Handoff can originate
 * from before(), handle(), or onException(), and each parent forwards its child result.
 * Action and verification logs reveal duplicate handoffs or premature parent success.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Definition\Node;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;
use Throwable;

final class RuntimeParent extends Step
{
    public static array $actions = [];

    public static array $verified = [];

    public static bool $verificationPasses = true;

    public function __construct(public int $depth = 1, public string $entry = 'handle', public mixed $value = 'backup result') {}

    private function children(): Decision
    {
        /**
         * Reuse the local name "backup" at every depth. The engine must prefix each
         * plan's keys with its distinct owner so nested children cannot collide.
         */
        return Decision::children([
            new Node('backup', self::class, inputs: ['depth' => $this->depth - 1, 'value' => $this->value]),
        ], resultFrom: 'backup');
    }

    public function before(Context $context): ?Decision
    {
        return $this->depth > 0 && $this->entry === 'before' ? $this->children() : null;
    }

    public function handle(): mixed
    {
        self::$actions[] = $this->depth;
        if ($this->entry === 'exception') {
            throw new \RuntimeException('Use backup');
        }

        return $this->depth > 0 ? $this->children() : $this->value;
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        return $this->children();
    }

    public function verify(Context $context, mixed $result): bool
    {
        self::$verified[] = [$this->depth, $result];

        return self::$verificationPasses;
    }
}
