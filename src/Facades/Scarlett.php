<?php

declare(strict_types=1);

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
