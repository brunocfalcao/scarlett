<?php

declare(strict_types=1);

/**
 * An instruction to read another step's saved result when this step runs.
 *
 * The string is a node key within the workflow, not a database row ID. The source
 * must already be a prerequisite through stages, parenthood, or recovery ownership.
 * Referencing it here supplies a value; it does not add scheduling dependencies.
 */

namespace Scarlett\Inputs;

/**
 * Defers reading a declared prerequisite result until this step is claimed.
 */
final readonly class FromResult
{
    public function __construct(public string $step) {}
}
