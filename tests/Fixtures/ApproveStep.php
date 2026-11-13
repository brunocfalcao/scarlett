<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;

class ApproveStep extends Step
{
    public function before(Context $context): ?Decision
    {
        return Decision::complete(new Amount(123), Approved::class);
    }

    public function handle(): never
    {
        throw new \LogicException('Action must not run for an accepted precondition result.');
    }
}
