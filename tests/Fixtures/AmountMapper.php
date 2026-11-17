<?php

declare(strict_types=1);

/**
 * Converts the Amount fixture to a plain integer and back.
 * PayloadCodec stores the configured mapper alias and this integer, then restores the
 * typed Amount before supplying it to constructors, handlers, or verification.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Contracts\PayloadMapper;

final class AmountMapper implements PayloadMapper
{
    public function supports(object $value): bool
    {
        return $value instanceof Amount;
    }

    public function encode(object $value): mixed
    {
        return $value->minorUnits;
    }

    public function decode(mixed $value): object
    {
        return new Amount($value);
    }
}
