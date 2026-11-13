<?php

declare(strict_types=1);

namespace Scarlett\Tests\Collision;

use Illuminate\Support\Facades\DB;
use Scarlett\ScarlettServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScarlettServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        Environment::configure($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        $connection = DB::connection();
        if ($connection->getDatabaseName() !== Environment::DATABASE || $connection->selectOne('SELECT DATABASE() AS name')->name !== Environment::DATABASE) {
            throw new \RuntimeException('Refusing to reset a database outside the Scarlett collision suite.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }
}
