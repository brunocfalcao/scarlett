<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class LiteralTypesStep extends Step
{
    public function __construct(private int|float $number, private ?string $label = null) {}

    public function handle(bool $enabled = true, int ...$increments): array
    {
        return [$this->number, $this->label, $enabled, $increments];
    }
}
