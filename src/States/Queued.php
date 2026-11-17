<?php

declare(strict_types=1);

/**
 * The engine has created a delivery token and its saved send request.
 * It may be scheduled for later; merely being queued does not release dependent work.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * An eligible attempt with a durable delivery intent; it may still have a future due time.
 */
class Queued extends StepState
{
    public static $name = 'queued';

    public static function phase(): Phase
    {
        return Phase::Queued;
    }

    public static function satisfiesDependency(): bool
    {
        return false;
    }

    public static function activatesRecovery(): bool
    {
        return false;
    }
}
