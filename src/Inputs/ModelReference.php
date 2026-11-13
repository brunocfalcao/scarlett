<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

final readonly class ModelReference
{
    public function __construct(public string $alias, public int|string $id, public ?string $connection = null) {}
}
