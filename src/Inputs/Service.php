<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

final readonly class Service
{
    public function __construct(public string $class) {}
}
