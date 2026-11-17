<?php

declare(strict_types=1);

/**
 * Accepts a caller-selected result and completion state before the action.
 * Its unreachable handle() exposes an incorrect precheck path, while configurable
 * state names let tests exercise allowed and rejected completion transitions.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;

class CompletionStep extends Step
{
    public function __construct(private string $state, private mixed $value = null) {}

    public function before(Context $context): ?Decision
    {
        return Decision::complete($this->value, $this->state);
    }

    public function handle(): never
    {
        throw new \LogicException('The precheck supplies the result.');
    }
}
