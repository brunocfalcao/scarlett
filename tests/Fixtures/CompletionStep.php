<?php

declare(strict_types=1);

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
