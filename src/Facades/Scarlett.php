<?php

declare(strict_types=1);

/**
 * Provides Laravel's static-looking Scarlett API backed by the container's Engine.
 *
 * Scarlett::start() and other facade calls resolve the same engine service used by
 * injected callers. The facade adds no execution logic; ownership, transactions,
 * and workflow progression remain in Engine and its collaborators.
 */

namespace Scarlett\Facades;

use Illuminate\Support\Facades\Facade;
use Scarlett\Engine;

/** @mixin Engine */
class Scarlett extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Engine::class;
    }
}
