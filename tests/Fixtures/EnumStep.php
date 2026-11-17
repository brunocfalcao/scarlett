<?php

declare(strict_types=1);

/**
 * Accepts both string-backed and integer-backed enums and returns them together.
 * The fixture exposes whether registered enum aliases restore the original PHP types
 * instead of returning only their raw JSON scalar values.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class EnumStep extends Step
{
    public function handle(StringStatus $status, IntPriority $priority): array
    {
        return ['status' => $status, 'priority' => $priority];
    }
}
