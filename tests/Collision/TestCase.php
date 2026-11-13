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
        Environment::assertIsolated();
        // Laravel's file-backed migrate:fresh truncates the file while PDO is open.
        // Close every connection before replacing a WAL database and its sidecars.
        if (Environment::driver() === 'sqlite') {
            $path = DB::connection()->getDatabaseName();
            DB::purge();
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            touch($path);
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        } else {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
        $this->beforeApplicationDestroyed(function (): void {
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::purge($name);
            }
        });
    }
}
