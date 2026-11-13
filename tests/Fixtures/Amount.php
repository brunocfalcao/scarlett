<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

final readonly class Amount
{
    public function __construct(public int $minorUnits) {}
}
