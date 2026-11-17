<?php

declare(strict_types=1);

/**
 * Connects the package to its host Laravel application's container and commands.
 *
 * Merge defaults while allowing application overrides, share the validated state
 * registry, discover package migrations, and expose optional configuration publishing.
 * The application owns scheduler choices; registering this provider does not start
 * workers, run migrations, or schedule recovery automatically.
 */

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
