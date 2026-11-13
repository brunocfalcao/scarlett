<?php

declare(strict_types=1);

namespace Scarlett;

use Illuminate\Support\ServiceProvider;
use Scarlett\Commands\RecoverCommand;

class ScarlettServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scarlett.php', 'scarlett');
        $this->app->singleton(\Scarlett\States\StateRegistry::class);
    }

    public function boot(): void
    {
        $this->commands([RecoverCommand::class]);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/scarlett.php' => config_path('scarlett.php')], 'scarlett-config');
    }
}
