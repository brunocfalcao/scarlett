<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class EnumStep extends Step
{
    public function handle(StringStatus $status, IntPriority $priority): array
    {
        return ['status' => $status, 'priority' => $priority];
    }
}
