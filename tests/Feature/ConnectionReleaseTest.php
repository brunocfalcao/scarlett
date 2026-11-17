<?php

declare(strict_types=1);

/**
 * Checks connection-release cases that must remain harmless without a server.
 *
 * An in-memory SQLite connection owns the database's lifetime, so disconnecting it
 * would erase the test data. An unused connection must also stay unopened: cleanup
 * should not create a handle merely to close it. Server-backed reconnect and
 * transaction boundaries are covered separately by collision tests.
 */

use Illuminate\Support\Facades\DB;
use Scarlett\Execution\ConnectionRelease;

it('keeps in-memory SQLite data alive when release is enabled', function (string $database) {
    config(['scarlett.release_database_connection' => true, 'database.connections.memory_test' => [
        'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
    ]]);
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('memory_test');
    try {
        $connection = DB::connection();
        $connection->statement('CREATE TABLE release_probe (value INTEGER)');
        $connection->table('release_probe')->insert(['value' => 42]);
        $pdo = $connection->getPdo();
        expect(app(ConnectionRelease::class)->release())->toBeFalse()
            ->and($connection->getRawPdo())->toBe($pdo)
            ->and($connection->table('release_probe')->value('value'))->toBe(42);
    } finally {
        DB::purge('memory_test');
        DB::setDefaultConnection($previous);
    }
})->with([':memory:', 'file:scarlett_release_test?mode=memory&cache=shared']);

it('does not open an unused default connection during cleanup', function () {
    config(['scarlett.release_database_connection' => true, 'database.connections.unused_release_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]);
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('unused_release_test');
    try {
        expect(array_key_exists('unused_release_test', DB::getConnections()))->toBeFalse()
            ->and(app(ConnectionRelease::class)->release())->toBeFalse()
            ->and(array_key_exists('unused_release_test', DB::getConnections()))->toBeFalse();
        $connection = DB::connection();
        expect($connection->getRawPdo())->toBeInstanceOf(Closure::class)
            ->and(app(ConnectionRelease::class)->release())->toBeFalse()
            ->and($connection->getRawPdo())->toBeInstanceOf(Closure::class);
    } finally {
        DB::purge('unused_release_test');
        DB::setDefaultConnection($previous);
    }
});
