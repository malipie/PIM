<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Database-maintenance gauges for the hot `object_values` table.
 *
 * The API already has one scrape target per tenant, so reading that
 * instance's own `pg_stat_user_tables` avoids adding a privileged, cross-stack
 * postgres-exporter. The query is constant-time and runs only on Prometheus
 * scrapes (currently every 15 seconds).
 */
final readonly class PostgresMaintenanceMetrics
{
    public function __construct(private Connection $connection)
    {
    }

    public function render(): string
    {
        try {
            $row = $this->connection->fetchAssociative(<<<'SQL'
                SELECT n_live_tup,
                       n_dead_tup,
                       n_ins_since_vacuum,
                       CASE
                           WHEN last_vacuum IS NULL AND last_autovacuum IS NULL THEN 1
                           ELSE 0
                       END AS never_vacuumed
                FROM pg_stat_user_tables
                WHERE relname = 'object_values'
                SQL);

            if (false === $row) {
                throw new RuntimeException('pg_stat_user_tables has no object_values row.');
            }

            $live = self::nonNegativeInteger($row, 'n_live_tup');
            $dead = self::nonNegativeInteger($row, 'n_dead_tup');
            $insertedSinceVacuum = self::nonNegativeInteger($row, 'n_ins_since_vacuum');
            $neverVacuumed = self::nonNegativeInteger($row, 'never_vacuumed');
        } catch (Throwable) {
            return <<<'METRICS'
                # HELP pim_postgres_maintenance_scrape_success Whether PostgreSQL maintenance statistics were read successfully.
                # TYPE pim_postgres_maintenance_scrape_success gauge
                pim_postgres_maintenance_scrape_success 0
                METRICS;
        }

        return <<<METRICS
            # HELP pim_postgres_maintenance_scrape_success Whether PostgreSQL maintenance statistics were read successfully.
            # TYPE pim_postgres_maintenance_scrape_success gauge
            pim_postgres_maintenance_scrape_success 1
            # HELP pim_postgres_object_values_live_tuples Estimated live tuples in object_values.
            # TYPE pim_postgres_object_values_live_tuples gauge
            pim_postgres_object_values_live_tuples {$live}
            # HELP pim_postgres_object_values_dead_tuples Estimated dead tuples waiting for vacuum in object_values.
            # TYPE pim_postgres_object_values_dead_tuples gauge
            pim_postgres_object_values_dead_tuples {$dead}
            # HELP pim_postgres_object_values_inserts_since_vacuum Tuples inserted into object_values since its last vacuum.
            # TYPE pim_postgres_object_values_inserts_since_vacuum gauge
            pim_postgres_object_values_inserts_since_vacuum {$insertedSinceVacuum}
            # HELP pim_postgres_object_values_never_vacuumed Whether object_values has no recorded manual or automatic vacuum.
            # TYPE pim_postgres_object_values_never_vacuumed gauge
            pim_postgres_object_values_never_vacuumed {$neverVacuumed}
            METRICS;
    }

    /**
     * DBAL returns PostgreSQL bigint statistics as numeric strings on some
     * drivers and as integers on others. Reject any other shape so a driver
     * change degrades to scrape_success=0 instead of publishing a fake zero.
     *
     * @param array<string, mixed> $row
     */
    private static function nonNegativeInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_int($value) && !\is_string($value)) {
            throw new UnexpectedValueException("PostgreSQL statistic {$key} is not numeric.");
        }

        $encoded = (string) $value;
        if (1 !== preg_match('/^\d+$/', $encoded)) {
            throw new UnexpectedValueException("PostgreSQL statistic {$key} is not a non-negative integer.");
        }

        return (int) $encoded;
    }
}
