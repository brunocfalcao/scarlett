<?php

declare(strict_types=1);

/**
 * Checks whether a requested successful state really represents an accepted result.
 *
 * Applications may register custom states, but registration does not automatically
 * make them successful or allow every transition. Both the state's meaning and the
 * transition from the current phase must agree before the engine stores success.
 */

namespace Scarlett\Execution;

use InvalidArgumentException;
use Scarlett\States\StateRegistry;

/**
 * Checks successful completion semantics before persisting an accepted result.
 * Registration alone is insufficient: the originating state must allow the transition.
 */
final class Completion
{
    public function validate(string $state, string $from): void
    {
        $registry = app(StateRegistry::class);
        if (! in_array($state, $registry->classes(), true) || ! $state::satisfiesDependency() || $state::skipsChildren()) {
            throw new InvalidArgumentException('An acceptable result requires a registered successful state.');
        }
        if (! collect($registry->transitions())->contains(fn (array $transition): bool => $transition[0] === $from && $transition[1] === $state)) {
            throw new InvalidArgumentException("Register the {$from} -> {$state} transition in scarlett.transitions.");
        }
    }
}
