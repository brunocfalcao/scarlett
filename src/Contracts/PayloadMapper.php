<?php

declare(strict_types=1);

namespace Scarlett\Contracts;

/**
 * Versioned application object mapping selected through a configured stable alias.
 * Encoded values must themselves be supported by PayloadCodec.
 */
interface PayloadMapper
{
    public function supports(object $value): bool;

    public function encode(object $value): mixed;

    public function decode(mixed $value): object;
}
