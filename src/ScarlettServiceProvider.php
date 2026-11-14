<?php

declare(strict_types=1);

namespace Scarlett;

use Illuminate\Support\ServiceProvider;
use Scarlett\Commands\ArchiveCommand;
use Scarlett\Commands\PruneThrottlesCommand;
use Scarlett\Commands\PurgeCommand;
use Scarlett\Commands\RecoverCommand;
use Scarlett\States\StateRegistry;

/**
 * Registers Laravel integration without scheduling work for the consumer.
 * Migrations stay in the package so normal application migrations discover upgrades.
 */
class ScarlettServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scarlett.php', 'scarlett');
        $this->app->singleton(StateRegistry::class);
    }

    public function boot(): void
    {
        $this->commands([RecoverCommand::class, ArchiveCommand::class, PurgeCommand::class, PruneThrottlesCommand::class]);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/scarlett.php' => config_path('scarlett.php')], 'scarlett-config');
    }
}
