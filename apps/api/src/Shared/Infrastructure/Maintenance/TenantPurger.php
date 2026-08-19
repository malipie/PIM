<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * AUD-019 / AUD-020 (W1-7) — irreversibly erases every row and storage
 * object that belongs to a single tenant, satisfying GDPR art. 17 (right
 * to erasure) for the hard-delete leg of tenant offboarding.
 *
 * Why ordered code-delete instead of FK CASCADE:
 *   24 of the 38 foreign keys pointing at `tenants` are ON DELETE RESTRICT
 *   (objects, object_values, users, assets, attributes, channels, the
 *   import_* / export_* clusters, …). They exist on purpose: a stray
 *   `DELETE FROM tenants` must NOT silently cascade through the whole
 *   catalogue. Re-pointing all 24 to CASCADE via migration would be a
 *   dangerous, hard-to-reverse weakening of that guard for every code path,
 *   not just offboarding. Instead we delete the dependent rows ourselves in
 *   strict child→parent order inside one transaction, then the tenant row.
 *   This is controlled, testable, idempotent and batched.
 *
 * RLS interaction (the subtle part):
 *   The runtime/CLI connects as `pim_app` (NOBYPASSRLS) and every
 *   tenant-scoped table has FORCE ROW LEVEL SECURITY. A plain `DELETE …
 *   WHERE tenant_id = :id` would therefore see ZERO rows (and silently
 *   delete nothing — a worse bug than the FK violation) unless the session
 *   GUC `app.current_tenant` is set to the tenant being purged. {@see purge}
 *   sets it for the duration of the delete and resets it in a finally, so
 *   the operation is scoped to exactly one tenant at the DB layer — the
 *   `demo` / `acme` tenants are physically invisible to these statements.
 *   The explicit `WHERE tenant_id = :id` predicate is defence in depth on
 *   top of RLS. Under the test schema (built from ORM metadata, no RLS
 *   policies) the GUC is a harmless no-op and the WHERE clause is the only
 *   guard — which is exactly what the offboarding test asserts.
 *
 * Memory (FrankenPHP worker mode, §3.10): deletes go through the DBAL
 * connection as set-based SQL (no entity hydration, no Identity Map growth),
 * so no per-row `clear()` is needed; the largest tenant's `object_values`
 * delete is a single server-side statement.
 *
 * Storage cascade: every Asset / export / import-upload object lives under
 * the `<tenant-uuid>/` path prefix (flysystem.yaml). After the DB rows are
 * gone we `deleteDirectory(<tenant-uuid>)` on each of the three named
 * operators. Storage failure is logged at error level and rethrown by
 * default so the caller never reports a clean purge while PII blobs linger;
 * see {@see purge} ordering note.
 */
final readonly class TenantPurger
{
    /**
     * Child→parent delete order across every tenant-scoped table. Each row
     * is `[table, tenantColumn]`. The order is a topological sort of the
     * inter-table foreign keys so a child is always gone before its parent:
     *
     *   1. import_* / export_* / channel_* leaf rows (logs, runs, sessions,
     *      placements, category nodes) before their profiles/schedules.
     *   2. object_values / object_relations / saved_views / bulk_edit_jobs
     *      before objects.
     *   3. objects before object_types and before bulk_sessions
     *      (objects → bulk_sessions / import_sessions is ON DELETE SET NULL,
     *      so objects must precede them to avoid dangling set-nulls; but
     *      semantically the children are purged first anyway).
     *   4. attribute_options before attributes before attribute_groups.
     *   5. channels / assets / api_* / backups (their children already gone).
     *   6. identity: invitations (RESTRICT → users/roles) before the
     *      user-scoped tokens, before users.
     *
     * Tables whose FK to `tenants` is already ON DELETE CASCADE (roles,
     * sso_providers, tenant_locales, menu_configurations,
     * tenant_agent_configs, channel_publication_profiles, refresh_tokens via
     * users, …) are STILL listed and deleted explicitly: relying on the
     * final `DELETE FROM tenants` cascade would make the row counts the
     * offboarding test asserts depend on cascade side effects, and leaves
     * orphan-prone tables (bulk_sessions, bulk_edit_jobs, smart_filter_presets,
     * refresh_tokens, saved_views) — which carry `tenant_id` but NO FK to
     * tenants — behind forever. Explicit is safer and idempotent.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array DELETE_ORDER = [
        // ── Import / Export / Channel leaves ───────────────────────────
        ['import_undo_log', 'tenant_id'],
        ['import_logs', 'tenant_id'],
        ['import_source_logs', 'tenant_id'],
        ['import_schedule_runs', 'tenant_id'],
        ['import_schedules', 'tenant_id'],
        ['import_sources', 'tenant_id'],
        ['import_sessions', 'tenant_id'],
        ['import_profiles', 'tenant_id'],
        ['import_staged_files', 'tenant_id'],
        ['export_sessions', 'tenant_id'],
        ['export_profiles', 'tenant_id'],
        ['object_channel_placements', 'tenant_id'],
        ['channel_category_node_mappings', 'tenant_id'],
        ['channel_category_nodes', 'tenant_id'],
        ['channel_publication_profiles', 'tenant_id'],

        // ── Object values / relations / view state ─────────────────────
        ['object_values', 'tenant_id'],
        ['object_relations', 'tenant_id'],
        ['saved_views', 'tenant_id'],
        ['smart_filter_presets', 'tenant_id'],
        ['bulk_edit_jobs', 'tenant_id'],

        // ── Objects, then the sessions they SET NULL-referenced, then types
        ['objects', 'tenant_id'],
        ['bulk_sessions', 'tenant_id'],
        ['object_types', 'tenant_id'],

        // ── Attributes ─────────────────────────────────────────────────
        ['attribute_options', 'tenant_id'],
        ['attributes', 'tenant_id'],
        ['attribute_groups', 'tenant_id'],

        // ── Channels / assets / API / backups ──────────────────────────
        ['channels', 'tenant_id'],
        ['assets', 'tenant_id'],
        ['api_keys', 'tenant_id'],
        ['api_profiles', 'tenant_id'],
        ['backups', 'tenant_id'],

        // ── Agent (#2956) — wiadomości i wywołania narzędzi wiszą na runach
        ['agent_tool_calls', 'tenant_id'],
        ['agent_messages', 'tenant_id'],
        ['agent_runs', 'tenant_id'],
        ['content_recipes', 'tenant_id'],
        ['brand_voice_profiles', 'tenant_id'],

        // ── Integracje (#2956) — logi przed przebiegami, przebiegi przed
        //    konfiguracją, wszystko przed połączeniem
        ['integration_sync_run_logs', 'tenant_id'],
        ['integration_sync_runs', 'tenant_id'],
        ['integration_sync_bindings', 'tenant_id'],
        ['integration_field_mappings', 'tenant_id'],
        ['integration_remote_fields', 'tenant_id'],
        ['integration_remote_endpoints', 'tenant_id'],
        ['api_webhook_deliveries', 'tenant_id'],
        ['integration_connections', 'tenant_id'],

        // ── Workflow (#2956) ───────────────────────────────────────────
        ['workflow_transitions', 'tenant_id'],
        ['workflow_tasks', 'tenant_id'],
        ['workflow_definitions', 'tenant_id'],

        // ── Feedy i katalogi PDF (#2956) ───────────────────────────────
        ['feed_run_logs', 'tenant_id'],
        ['feed_runs', 'tenant_id'],
        ['feed_pull_stats', 'tenant_id'],
        ['feed_profiles', 'tenant_id'],
        ['catalog_runs', 'tenant_id'],
        ['catalog_profiles', 'tenant_id'],

        // ── Pulpit, powiadomienia, zmiany oczekujące, audyt (#2956) ─────
        ['dashboard_alert_acks', 'tenant_id'],
        ['dashboard_snapshots', 'tenant_id'],
        ['notifications', 'tenant_id'],
        ['pending_changes', 'tenant_id'],
        ['audit_logs', 'tenant_id'],

        // ── Identity (children of users before users) ──────────────────
        ['invitations', 'tenant_id'],
        ['api_tokens', 'tenant_id'],
        ['password_reset_tokens', 'tenant_id'],
        ['user_tenant_memberships', 'tenant_id'],
        ['refresh_tokens', 'tenant_id'],
        ['users', 'tenant_id'],

        // ── Remaining tenant-scoped config (CASCADE → tenants anyway) ───
        ['roles', 'tenant_id'],
        ['sso_providers', 'tenant_id'],
        ['tenant_locales', 'tenant_id'],
        ['menu_configurations', 'tenant_id'],
        ['tenant_agent_configs', 'tenant_id'],
    ];

    public function __construct(
        private Connection $connection,
        private FilesystemOperator $assetsStorage,
        private FilesystemOperator $importsStorage,
        private FilesystemOperator $exportsStorage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Hard-deletes one tenant: all dependent rows (in FK order) + the tenant
     * row in a single transaction, then the tenant's object-storage prefix
     * across all three buckets.
     *
     * Ordering rationale (DB before storage): the database row is the source
     * of truth for "this tenant exists". We commit the DB erasure first so a
     * crash mid-storage-delete leaves NO dangling tenant pointing at blobs;
     * the worst case is orphan blobs under a now-unknown prefix, which the
     * logged error flags for a manual / GC sweep — never a live tenant whose
     * data is half-gone. Storage failure is rethrown so the caller does not
     * count the tenant as cleanly purged.
     *
     * Idempotent: re-running on an already-purged tenant deletes 0 rows and
     * `deleteDirectory` on a missing prefix is a no-op.
     *
     * @return int total number of dependent + tenant rows deleted from the DB
     */
    public function purge(Uuid $tenantId): int
    {
        $idString = $tenantId->toRfc4122();

        $deleted = $this->connection->transactional(static function (Connection $conn) use ($idString): int {
            // Scope the session to this tenant so FORCE RLS lets the deletes
            // see the rows. Session scope (is_local=false) survives the
            // implicit per-statement commits inside the transaction; the
            // finally below resets it for the pooled worker connection.
            $conn->executeStatement(
                "SELECT set_config('app.current_tenant', :t, false)",
                ['t' => $idString],
            );

            // tenant-safe: tenant offboarding hard-delete — deletes ONLY the
            // explicitly-resolved soft-deleted tenant's rows (WHERE <col> =
            // :tenant) with the RLS GUC pinned to that tenant. TenantFilter
            // scopes to the *current request* tenant, which is exactly wrong
            // for purging a different tenant, so it is intentionally bypassed.
            $total = 0;
            try {
                // #2956 — zaproszenia PRZEKRACZAJĄ granicę tenanta. `invited_by_user_id`
                // wskazuje osobę, która zaprosiła, a operator zapraszający właściciela
                // nowego klienta ze swojego konta tworzy zaproszenie w tenancie A
                // wskazujące użytkownika z tenanta B. To normalna ścieżka, nie nadużycie.
                //
                // Kasowanie „po tenancie" takiego wiersza nie obejmuje, więc usunięcie
                // użytkowników wywraca się na `fk_invitations_invited_by` (ON DELETE
                // RESTRICT) i CAŁY purge pada. Zmierzone na produkcji: 3 z 7 zaproszeń.
                //
                // Ten krok idzie PRZED pętlą, bo dotyczy wierszy, których pętla z
                // definicji nie widzi — należą do innego tenanta.
                $total += (int) $conn->executeStatement(
                    'DELETE FROM invitations WHERE invited_by_user_id IN '
                    .'(SELECT id FROM users WHERE tenant_id = :tenant)',
                    ['tenant' => $idString],
                );

                foreach (self::DELETE_ORDER as [$table, $column]) {
                    // $table / $column are compile-time constants from
                    // DELETE_ORDER, never external input — safe to inline.
                    $total += (int) $conn->executeStatement(
                        \sprintf('DELETE FROM %s WHERE %s = :tenant', $table, $column),
                        ['tenant' => $idString],
                    );
                }

                // `tenants` is NOT RLS-protected (no tenant_id of its own),
                // so this delete is visible regardless of the GUC.
                $total += (int) $conn->executeStatement(
                    'DELETE FROM tenants WHERE id = :id',
                    ['id' => $idString],
                );
            } finally {
                $conn->executeStatement("SELECT set_config('app.current_tenant', '', false)");
            }

            return $total;
        });

        $this->purgeStorage($idString);

        return $deleted;
    }

    /**
     * Removes the `<tenant-uuid>/` prefix from each of the three buckets.
     * Throws if any operator fails so the caller treats the purge as
     * incomplete — DB rows are already gone (see ordering note), but the
     * operator MUST know blobs may linger.
     */
    private function purgeStorage(string $tenantId): void
    {
        $operators = [
            'assets' => $this->assetsStorage,
            'imports' => $this->importsStorage,
            'exports' => $this->exportsStorage,
        ];

        $failures = [];
        foreach ($operators as $bucket => $operator) {
            try {
                // Tenant isolation rides on the path prefix `<tenant-uuid>/`
                // (flysystem.yaml). deleteDirectory removes every object
                // under it; a missing prefix is a no-op (idempotent).
                $operator->deleteDirectory($tenantId);
            } catch (Throwable $e) {
                $this->logger->error(
                    'Tenant storage purge failed; DB rows already deleted, blobs may linger under prefix.',
                    ['tenant_id' => $tenantId, 'bucket' => $bucket, 'exception' => $e],
                );
                $failures[] = $bucket;
            }
        }

        if ([] !== $failures) {
            throw new TenantStoragePurgeException($tenantId, $failures);
        }
    }
}
