<?php

declare(strict_types=1);

namespace Scarlett\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Scarlett\ScarlettServiceProvider;
use Scarlett\Tests\Collision\Environment;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ScarlettServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        if (getenv('SCARLETT_TEST_DRIVER') && Environment::driver() !== 'sqlite') {
            Environment::configure($app, collision: false);
            $app['config']->set('queue.default', 'sync');

            return;
        }
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        if (getenv('SCARLETT_TEST_DRIVER') && Environment::driver() !== 'sqlite') {
            Environment::assertIsolated();
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

            return;
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }
}
