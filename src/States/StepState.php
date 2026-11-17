<?php

declare(strict_types=1);

/**
 * Defines the scheduling meaning shared by built-in and application-defined states.
 *
 * A state's phase says whether it can still run. satisfiesDependency() says whether
 * later stages may proceed; activatesRecovery() says whether rescue work should run.
 * skipsChildren() distinguishes an intentional no-op from ordinary cancellation.
 * Those answers drive progression and aggregate status, not the displayed alias alone.
 * StateRegistry supplies allowed classes and transitions to the model-state package.
 */

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
