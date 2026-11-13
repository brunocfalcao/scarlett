<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

final readonly class FromResult
{
    public function __construct(public string $step) {}
}
