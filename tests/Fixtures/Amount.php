<?php

declare(strict_types=1);

/**
 * A minimal immutable value object storing an integer amount in minor units.
 * It deliberately needs a registered mapper so tests can distinguish supported custom
 * object restoration from arbitrary PHP object serialization.
 */

namespace Scarlett\Tests\Fixtures;

final readonly class Amount
{
    public function __construct(public int $minorUnits) {}
}
