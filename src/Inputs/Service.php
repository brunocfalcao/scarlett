<?php

declare(strict_types=1);

/**
 * An instruction to resolve a Laravel container entry at execution time.
 *
 * Despite the property name, class can also name a registered container alias.
 * Saving the lookup name avoids serializing a live service and allows each worker
 * to receive its own current instance when restoring the step's named inputs.
 */

namespace Scarlett\Inputs;

/**
 * An explicit container lookup performed when inputs are restored, never at admission.
 */
final readonly class Service
{
    public function __construct(public string $class) {}
}
