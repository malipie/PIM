<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #3034 — make the `where-used` instance counters affordable on a loaded tenant.
 *
 * Measured on production (tenant `harmon`, 101 909 objects / 1 268 172
 * `object_values`) before this migration:
 *
 *   SELECT attribute_id, COUNT(DISTINCT object_id)
 *   FROM object_values GROUP BY attribute_id;
 *   -> Seq Scan + GroupAggregate, Sort Method: external merge  Disk: 52 MB
 *   -> Execution Time: 1 619 ms
 *
 * Two changes, both aimed at that plan:
 *
 * 1. A covering index in `(tenant_id, attribute_id, object_id)` order. Every
 *    read goes through the RLS predicate `tenant_id = current_setting(…)`, so
 *    tenant_id leads; the remaining columns let Postgres answer the aggregate
 *    from an Index Only Scan that is already grouped, dropping both the heap
 *    access and the 52 MB external sort.
 *
 * 2. Per-table autovacuum thresholds. `object_values` grows by ~1M rows per
 *    catalogue import, and the cluster defaults (20% dead / 10% modified)
 *    translate to a quarter of a million dead tuples before autovacuum wakes
 *    up. On the production tenant the table had never been vacuumed at all:
 *    the visibility map was empty and an Index Only Scan degraded into
 *    1 347 200 heap fetches, which is what made the counters slow enough to
 *    saturate the FrankenPHP worker pool.
 *
 * A plain (transactional) CREATE INDEX is used rather than CONCURRENTLY,
 * following Version20260617210000: CONCURRENTLY needs isTransactional(): false
 * and forfeits a clean up/down/up round-trip. The trade-off is a SHARE lock
 * that blocks writes to `object_values` for the duration of the build —
 * roughly 10-20 s at 1.27M rows, so this migration wants a deploy window
 * rather than a live-traffic moment.
 *
 * NOT included, because VACUUM cannot run inside a transaction: the one-off
 * `VACUUM (ANALYZE) object_values;` that rebuilds the visibility map on an
 * already-loaded database. Run it once per tenant after deploying — the
 * procedure, the diagnosis and the measured before/after are in
 * docs/runbook/postgres-maintenance.md. Fresh databases do not need it;
 * autovacuum keeps up from an empty table given the thresholds set below.
 */
final class Version20260826111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cover object_values (tenant_id, attribute_id, object_id) for where-used counters and tighten its autovacuum thresholds (#3034).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS object_values_tenant_attr_object_idx'
            .' ON object_values (tenant_id, attribute_id, object_id)'
        );

        $this->addSql(
            'ALTER TABLE object_values SET ('
            .' autovacuum_vacuum_scale_factor = 0.02,'
            .' autovacuum_analyze_scale_factor = 0.01'
            .' )'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS object_values_tenant_attr_object_idx');

        $this->addSql(
            'ALTER TABLE object_values RESET ('
            .' autovacuum_vacuum_scale_factor,'
            .' autovacuum_analyze_scale_factor'
            .' )'
        );
    }
}
