<?php

declare(strict_types=1);

namespace Scarlett\Tests\Collision;

use Illuminate\Foundation\Application;

final class Environment
{
    public const DATABASE = 'scarlett_collision_tests';

    public static function configure(Application $app): void
    {
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => getenv('SCARLETT_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('SCARLETT_TEST_PORT') ?: '3306', 'database' => self::DATABASE,
            'username' => getenv('SCARLETT_TEST_USER') ?: 'root', 'password' => getenv('SCARLETT_TEST_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        $app['config']->set('queue.default', 'null');
        $app['config']->set('scarlett.retry.backoff_seconds', [0]);
    }
}
