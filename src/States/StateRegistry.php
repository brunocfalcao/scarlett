<?php

declare(strict_types=1);

/**
 * Builds the checked list of state aliases and legal state changes.
 *
 * Database rows store stable aliases rather than full class names. Aliases must be
 * unique, and state semantics must agree: unfinished work cannot release successors,
 * and a successful state cannot also activate failure recovery. Custom transitions
 * may extend the machine but cannot reopen final work. Cache only while configuration
 * is unchanged so a changed application registry is not served stale mappings.
 */

namespace Scarlett\States;

use InvalidArgumentException;
use ReflectionClass;
use Spatie\ModelStates\Transition;

/**
 * Validates stable aliases and scheduling semantics for configured states.
 * Cached mappings are rebuilt when configuration changes; settled work cannot reopen.
 */
final class StateRegistry
{
    private ?array $stateConfiguration = null;

    private ?array $transitionConfiguration = null;

    private array $cachedClasses = [];

    private array $cachedTransitions = [];

    public function classes(): array
    {
        $configuration = config('scarlett.states', []);
        if (! is_array($configuration)) {
            throw new InvalidArgumentException('scarlett.states must be an array of state classes.');
        }
        if ($this->stateConfiguration === $configuration) {
            return $this->cachedClasses;
        }
        $classes = array_merge([Pending::class, Queued::class, Running::class, WaitingForChildren::class,
            Completed::class, Skipped::class, Failed::class, Stopped::class, Cancelled::class], $configuration);
        /**
         * Aliases are what saved rows contain, so duplicates would restore the wrong
         * class. Check scheduling semantics here too: a nonfinal state cannot announce
         * success, and final success cannot simultaneously request failure recovery.
         */
        $aliases = [];
        foreach ($classes as $class) {
            if (! is_string($class) || ! is_subclass_of($class, StepState::class)) {
                throw new InvalidArgumentException('States must extend StepState.');
            }
            if (! (new ReflectionClass($class))->isInstantiable()) {
                throw new InvalidArgumentException('States must be concrete StepState classes.');
            }
            $alias = $class::getMorphClass();
            if (isset($aliases[$alias]) || $alias === $class) {
                throw new InvalidArgumentException('Each state requires a unique stable alias.');
            }
            if (! mb_check_encoding($alias, 'UTF-8') || mb_strlen($alias, 'UTF-8') > 255) {
                throw new InvalidArgumentException('Stable state aliases must be valid UTF-8 and at most 255 characters.');
            }
            if (($class::satisfiesDependency() || $class::activatesRecovery()) && $class::phase() !== Phase::Settled) {
                throw new InvalidArgumentException('Only settled states can satisfy dependencies or activate recovery.');
            }
            if ($class::satisfiesDependency() && $class::activatesRecovery()) {
                throw new InvalidArgumentException('A state cannot satisfy dependencies and activate recovery.');
            }
            $aliases[$alias] = $class;
        }

        $this->stateConfiguration = $configuration;
        $this->cachedClasses = $classes;
        $this->transitionConfiguration = null;

        return $classes;
    }

    public function transitions(): array
    {
        $classes = $this->classes();
        $configuration = config('scarlett.transitions', []);
        if (! is_array($configuration)) {
            throw new InvalidArgumentException('scarlett.transitions must be an array of transition definitions.');
        }
        if ($this->transitionConfiguration === $configuration) {
            return $this->cachedTransitions;
        }
        $transitions = [
            [Pending::class, Queued::class], [Pending::class, Skipped::class], [Queued::class, Skipped::class], [Pending::class, Cancelled::class],
            [Queued::class, Running::class], [Queued::class, Cancelled::class],
            [WaitingForChildren::class, Queued::class],
            [Running::class, Queued::class], [Running::class, WaitingForChildren::class],
        ];
        foreach ([Running::class, WaitingForChildren::class] as $from) {
            foreach ([Completed::class, Skipped::class, Failed::class, Stopped::class, Cancelled::class] as $to) {
                $transitions[] = [$from, $to];
            }
        }
        /**
         * A custom transition names source and destination, plus an optional transition
         * class. Require registered endpoints and forbid leaving a final state; retained
         * finished work must not become executable again through a custom transition.
         */
        foreach ($configuration as $transition) {
            if (! is_array($transition) || ! array_is_list($transition) || count($transition) < 2 || count($transition) > 3
                || ! isset($transition[0], $transition[1]) || ! is_string($transition[0]) || ! is_string($transition[1])
                || (isset($transition[2]) && $transition[2] !== null && (! is_string($transition[2]) || ! is_subclass_of($transition[2], Transition::class)))) {
                throw new InvalidArgumentException('scarlett.transitions entries must contain two state classes and an optional transition class.');
            }
            if (! in_array($transition[0], $classes, true) || ! in_array($transition[1], $classes, true)
                || $transition[0]::phase() === Phase::Settled) {
                throw new InvalidArgumentException('Custom transitions require registered states and cannot reopen settled work.');
            }
            if (isset($transition[2]) && ! (new ReflectionClass($transition[2]))->isInstantiable()) {
                throw new InvalidArgumentException('scarlett.transitions transition classes must be concrete.');
            }
            $transitions[] = $transition;
        }

        $this->transitionConfiguration = $configuration;
        $this->cachedTransitions = $transitions;

        return $transitions;
    }
}
