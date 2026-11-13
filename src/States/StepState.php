<?php

declare(strict_types=1);

namespace Scarlett\States;

use Illuminate\Support\Collection;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/** State semantics are shared by progression, aggregation, recovery and retention. */
abstract class StepState extends State
{
    abstract public static function phase(): Phase;

    public static function satisfiesDependency(): bool
    {
        return false;
    }

    public static function skipsChildren(): bool
    {
        return false;
    }

    public static function activatesRecovery(): bool
    {
        return false;
    }

    public static function config(): StateConfig
    {
        $registry = app(StateRegistry::class);

        return (new StateConfig(self::class))->default(Pending::class)
            ->registerState($registry->classes())->allowTransitions($registry->transitions());
    }

    public static function getStateMapping(): Collection
    {
        return collect(app(StateRegistry::class)->classes())->keyBy(fn (string $class): string => $class::getMorphClass());
    }
}
