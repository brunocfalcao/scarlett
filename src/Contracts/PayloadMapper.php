<?php

declare(strict_types=1);

/**
 * The application extension point for storing a custom value object.
 *
 * PayloadCodec asks registered mappers whether they support an object, stores the
 * selected mapper's alias and encoded data, then uses that alias to decode it later.
 * Encoding must return supported payload values. Mapping can run during diagnostics
 * as well as result persistence, so it must not perform business side effects.
 */

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
