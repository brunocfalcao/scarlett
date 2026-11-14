<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

/**
 * An explicit live model lookup by registered alias and identifier, not a model snapshot.
 */
final readonly class ModelReference
{
    public function __construct(public string $alias, public int|string $id, public ?string $connection = null) {}
}
