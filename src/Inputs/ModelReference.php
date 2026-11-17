<?php

declare(strict_types=1);

/**
 * An explicit request to reload an Eloquent row when a worker restores an input.
 *
 * Alias selects a model class registered in scarlett.models; id selects its row.
 * This is a live lookup, not a snapshot of the row at workflow creation. A supplied
 * connection overrides the model's connection; null preserves the model's default.
 * Missing rows fail input preparation rather than yielding a partially usable model.
 */

namespace Scarlett\Inputs;

/**
 * An explicit live model lookup by registered alias and identifier, not a model snapshot.
 */
final readonly class ModelReference
{
    public function __construct(public string $alias, public int|string $id, public ?string $connection = null) {}
}
