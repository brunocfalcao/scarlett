<?php

declare(strict_types=1);

/**
 * Builds the dedicated database configuration shared by collision parents and workers.
 *
 * Only scarlett_collision_tests, or its fixed temporary SQLite file, may be reset.
 * assertIsolated() checks both configured and actual server database identity before
 * migrations. MySQL/PostgreSQL expose real lock-wait observations; SQLite uses a
 * file-backed WAL database and immediate transactions to serialize independent writers.
 * Queue sends are discarded in lock-only tests so the test controls every attempt.
 */

namespace Scarlett\Tests\Collision;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class Environment
{
    public const DATABASE = 'scarlett_collision_tests';

    public static function driver(): string
    {
        $driver = getenv('SCARLETT_TEST_DRIVER') ?: 'mysql';
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException('SCARLETT_TEST_DRIVER must be sqlite, mysql or pgsql.');
        }

        return $driver;
    }

    public static function configure(Application $app, bool $collision = true): void
    {
        $driver = self::driver();
        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.'.$driver, [
            'driver' => $driver, 'host' => getenv('SCARLETT_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('SCARLETT_TEST_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'), 'database' => self::DATABASE,
            'username' => getenv('SCARLETT_TEST_USER') ?: ($driver === 'pgsql' ? 'postgres' : 'root'), 'password' => getenv('SCARLETT_TEST_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        if ($driver === 'pgsql') {
            $app['config']->set('database.connections.pgsql.charset', 'utf8');
        }
        if ($driver === 'sqlite') {
            $path = sys_get_temp_dir().'/'.self::DATABASE.'.sqlite';
            if (! file_exists($path)) {
                touch($path);
            }
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite', 'database' => realpath($path), 'prefix' => '',
                'foreign_key_constraints' => true, 'journal_mode' => 'WAL',
                'busy_timeout' => 10000, 'transaction_mode' => 'IMMEDIATE',
            ]);
        }
        if ($collision) {
            $app['config']->set('queue.default', 'null');
            $app['config']->set('scarlett.retry.backoff_seconds', [0]);
        }
    }

    public static function assertIsolated(): void
    {
        /**
         * Read the database's own identity, not just configuration. A connection can
         * resolve somewhere unexpected; no destructive reset is allowed unless both
         * identities match this suite's dedicated target.
         */
        $connection = DB::connection();
        $expected = self::driver() === 'sqlite' ? realpath(sys_get_temp_dir().'/'.self::DATABASE.'.sqlite') : self::DATABASE;
        $actual = match (self::driver()) {
            'mysql' => $connection->selectOne('SELECT DATABASE() AS name')->name,
            'pgsql' => $connection->selectOne('SELECT current_database() AS name')->name,
            'sqlite' => $connection->selectOne('PRAGMA database_list')->file,
        };
        if ($connection->getDatabaseName() !== $expected || $actual !== $expected) {
            throw new RuntimeException('Refusing to reset a database outside the Scarlett test suite.');
        }
    }

    public static function waitingWorkers(string $table): int
    {
        if (self::driver() === 'pgsql') {
            DB::selectOne('SELECT pg_stat_clear_snapshot()');
        }
        $row = match (self::driver()) {
            'mysql' => DB::selectOne("SELECT COUNT(DISTINCT thread_id) AS waiting FROM performance_schema.data_locks WHERE object_schema = ? AND object_name = ? AND lock_status = 'WAITING'", [self::DATABASE, $table]),
            'pgsql' => DB::selectOne("SELECT COUNT(*) AS waiting FROM pg_stat_activity WHERE datname = ? AND wait_event_type = 'Lock' AND query LIKE ?", [self::DATABASE, '%'.$table.'%']),
            default => throw new RuntimeException('SQLite has no server lock inspection view.'),
        };

        return (int) $row->waiting;
    }

    /** SQLite IMMEDIATE transactions serialize writers, so the other commit must precede the next writer transaction. */
    public static function concurrentCommit(callable $operation): mixed
    {
        /**
         * SQLite cannot let another writer commit while this connection holds an
         * IMMEDIATE transaction. Temporarily finish it, perform the competing commit,
         * then begin a new writer transaction. Server lanes retain the original snapshot.
         */
        $serialized = self::driver() === 'sqlite';
        if ($serialized) {
            DB::commit();
        }
        DB::setDefaultConnection('concurrent');
        try {
            return $operation();
        } finally {
            DB::setDefaultConnection(self::driver());
            if ($serialized) {
                DB::beginTransaction();
            }
        }
    }
}
