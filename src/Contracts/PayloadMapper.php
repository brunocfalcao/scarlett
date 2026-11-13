<?php

declare(strict_types=1);

namespace Scarlett\Contracts;

interface PayloadMapper
{
    public function supports(object $value): bool;

    public function encode(object $value): mixed;

    public function decode(mixed $value): object;
}
