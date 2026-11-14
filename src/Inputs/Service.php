<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

/**
 * An explicit container lookup performed when inputs are restored, never at admission.
 */
final readonly class Service
{
    public function __construct(public string $class) {}
}
