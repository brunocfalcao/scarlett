<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

/**
 * Defers reading a declared prerequisite result until this step is claimed.
 */
final readonly class FromResult
{
    public function __construct(public string $step) {}
}
