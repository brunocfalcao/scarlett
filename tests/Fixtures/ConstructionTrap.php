<?php

declare(strict_types=1);

/**
 * Fails when constructed, so tests can prove restoration rejects a configured class
 * before instantiating it. A class that is not a model or mapper must never be built
 * just to discover that it does not qualify.
 */

namespace Scarlett\Tests\Fixtures;

use RuntimeException;

final class ConstructionTrap
{
    public function __construct()
    {
        throw new RuntimeException('Constructed before validation.');
    }
}
