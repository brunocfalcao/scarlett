<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Illuminate\Contracts\Config\Repository;
use Scarlett\Step;

class TypedStep extends Step
{
    public static array $received = [];

    public function __construct(public Amount $amount) {}

    public function handle(Repository $settings, int $multiplier = 1): Amount
    {
        self::$received[] = $this->amount->minorUnits;

        return new Amount($this->amount->minorUnits * $multiplier);
    }
}
