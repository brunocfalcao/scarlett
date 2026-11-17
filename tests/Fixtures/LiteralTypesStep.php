<?php

declare(strict_types=1);

/**
 * Combines a union-typed constructor input, a nullable default, a boolean, and
 * variadic integers. Returning the received values lets tests detect unwanted scalar
 * coercion, lost falsey values, or incorrect variadic list expansion.
 */

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
