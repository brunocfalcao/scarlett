<?php

declare(strict_types=1);

/**
 * Optionally closes an idle default database connection around long-running work.
 *
 * An external request may take a long time without using the database. Opted-in
 * workers can release their connection then reconnect on the next query. Never do
 * this inside a transaction or for an in-memory database: closing either would
 * change the operation's meaning or destroy data. Inspect existing handles only;
 * opening a connection merely to close it would defeat the purpose.
 */

namespace Scarlett\Execution;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * Releases only the existing default connection at an opted-in execution boundary.
 * Durable engine state remains in the database; the next query reconnects normally.
 */
final class ConnectionRelease
{
    public function release(): bool
    {
        if (config('scarlett.release_database_connection') !== true) {
            return false;
        }
        $connection = DB::getConnections()[DB::getDefaultConnection()] ?? null;
        if ($connection === null || $connection->transactionLevel() !== 0 || ! $this->canReconnect($connection)) {
            return false;
        }

        /**
         * Raw access must never open a connection merely to close it. Inspect read
         * and direct handles too: consumers may have opened a transaction via PDO.
         */
        $handles = [$connection->getRawPdo(), $connection->getRawReadPdo()];
        if (method_exists($connection, 'getRawDirectPdo')) {
            $handles[] = $connection->getRawDirectPdo();
        }
        $connected = false;
        try {
            foreach ($handles as $handle) {
                if (! $handle instanceof PDO) {
                    continue;
                }
                if ($handle->inTransaction()
                    || ($connection->getDriverName() !== 'sqlite' && $handle->getAttribute(PDO::ATTR_PERSISTENT))) {
                    return false;
                }
                $connected = true;
            }
        } catch (PDOException) {
            /**
             * Optional housekeeping cannot replace an attempt's outcome with a
             * diagnostic failure. Leave an uninspectable connection untouched.
             */
            return false;
        }
        if (! $connected) {
            return false;
        }
        $connection->disconnect();

        return true;
    }

    private function canReconnect(Connection $connection): bool
    {
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'pgsql'], true)
            || ($connection->getConfig('options')[PDO::ATTR_PERSISTENT] ?? false)) {
            return false;
        }

        /**
         * Only ordinary file-backed SQLite is eligible. Memory and URI stores
         * can disappear on disconnect or have connection-dependent lifetimes.
         */
        return $connection->getDriverName() !== 'sqlite'
            || is_file($connection->getDatabaseName());
    }
}
