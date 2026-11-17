<?php

declare(strict_types=1);

/**
 * An integer-backed enum used alongside StringStatus. Numeric backing values must
 * retain enum identity across payload encoding, JSON persistence, and restoration.
 */

namespace Scarlett\Tests\Fixtures;

enum IntPriority: int
{
    case Low = 1;
    case High = 10;
}
