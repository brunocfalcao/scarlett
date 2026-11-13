<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Step;

class VerifiedAmountStep extends Step
{
    public static int $verifications = 0;

    public function handle(): Amount
    {
        return new Amount(42);
    }

    public function verify(Context $context, mixed $result): bool
    {
        self::$verifications++;

        return true;
    }
}
