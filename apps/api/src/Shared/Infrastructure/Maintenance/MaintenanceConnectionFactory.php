<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use RuntimeException;

/**
 * Builds throwaway connections to the `postgres` maintenance database from an
 * application connection's params (a database cannot be dropped from a
 * connection to itself, and advisory-lock holders must survive the drop).
 *
 * GOLIVE #2178 — also owns the advisory-lock key that serialises dev database
 * lifecycle operations: `pim:db:reset` FORCE-drops the database, which kills
 * the worker's Messenger session; the restarted worker's entrypoint re-runs
 * `pim:dev:ensure-seeded`, which sees a half-reset (empty) database and
 * chains its own reset — two unserialised resets then kill each other's
 * connections mid-fixtures. Postgres advisory locks are cluster-wide (not
 * per-database), so a lock taken on the maintenance DB survives the drop of
 * the application database and correctly serialises every lifecycle actor.
 */
final class MaintenanceConnectionFactory
{
    /**
     * Arbitrary-but-stable bigint key for pg_advisory_lock. Shared by
     * DatabaseResetCommand (holds it for the whole reset) and
     * EnsureSeededCommand (waits on it, then re-checks whether a concurrent
     * reset already seeded the database).
     */
    public const int LIFECYCLE_LOCK_KEY = 727968273;

    public static function fromConnection(Connection $connection): Connection
    {
        /** @var array{dbname?: string, url?: string} $params */
        $params = $connection->getParams();
        if ('' === ($params['dbname'] ?? '')) {
            throw new RuntimeException('Cannot resolve the database name from the connection params.');
        }

        $params['dbname'] = 'postgres';
        // `url` (when present) would win over the overridden dbname.
        unset($params['url']);

        return DriverManager::getConnection($params);
    }
}
