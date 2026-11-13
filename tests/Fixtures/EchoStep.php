<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class EchoStep extends Step
{
    public function handle(mixed $value = null): mixed
    {
        return $value;
    }
}
