<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class SlowStep extends Step
{
    public function handle(int $seconds): void
    {
        sleep($seconds);
    }
}
