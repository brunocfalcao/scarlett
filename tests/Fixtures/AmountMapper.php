<?php

declare(strict_types=1);

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
