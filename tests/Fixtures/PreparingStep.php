<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Closure;
use Scarlett\Step;

class PreparingStep extends Step
{
    public function __construct(Closure $prepare)
    {
        $prepare();
    }

    public function handle(): int
    {
        return 42;
    }
}
