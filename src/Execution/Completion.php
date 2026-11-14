<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use InvalidArgumentException;
use Scarlett\States\StateRegistry;

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
