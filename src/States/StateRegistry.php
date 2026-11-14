<?php

declare(strict_types=1);

namespace Scarlett\States;

use InvalidArgumentException;

final class StateRegistry
{
    private ?array $stateConfiguration = null;

    private ?array $transitionConfiguration = null;

    private array $cachedClasses = [];

    private array $cachedTransitions = [];

    public function classes(): array
    {
        $configuration = config('scarlett.states', []);
        if ($this->stateConfiguration === $configuration) {
            return $this->cachedClasses;
        }
        $classes = array_merge([Pending::class, Queued::class, Running::class, WaitingForChildren::class,
            Completed::class, Skipped::class, Failed::class, Stopped::class, Cancelled::class], $configuration);
        $aliases = [];
        foreach ($classes as $class) {
            if (! is_subclass_of($class, StepState::class)) {
                throw new InvalidArgumentException('States must extend StepState.');
            }
            $alias = $class::getMorphClass();
            if (isset($aliases[$alias]) || $alias === $class) {
                throw new InvalidArgumentException('Each state requires a unique stable alias.');
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
        foreach ($configuration as $transition) {
            if (! in_array($transition[0], $classes, true) || ! in_array($transition[1], $classes, true)
                || $transition[0]::phase() === Phase::Settled) {
                throw new InvalidArgumentException('Custom transitions require registered states and cannot reopen settled work.');
            }
            $transitions[] = $transition;
        }

        $this->transitionConfiguration = $configuration;
        $this->cachedTransitions = $transitions;

        return $transitions;
    }
}
