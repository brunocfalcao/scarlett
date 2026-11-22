<?php

declare(strict_types=1);

/**
 * Counts its own construction, so tests can prove PayloadCodec resolves a configured
 * mapper once per encode call rather than once per stored object.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Contracts\PayloadMapper;

final class CountingAmountMapper implements PayloadMapper
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

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
