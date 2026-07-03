<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;

/**
 * #2156 — re-asserts the session-scoped Postgres GUC `app.current_tenant`
 * that the RLS policies read.
 *
 * RlsContextListener (HTTP) and TenantRlsGucMiddleware (workers) set that
 * GUC once, on entry. Under FrankenPHP worker mode the DBAL connection is
 * long-lived and can be reused / silently reconnected mid-request; a
 * session variable is per physical connection, so a reconnect drops it.
 * Any RLS-protected WRITE issued afterwards then evaluates the policy with
 * an empty tenant and, under FORCE ROW LEVEL SECURITY, silently affects
 * ZERO rows (no error) — e.g. an agent run's terminal-status UPDATE that
 * reported success but never persisted.
 *
 * Call {@see reassert()} immediately before such a write to guarantee the
 * GUC is present on whatever connection the flush lands on. Idempotent: if
 * the GUC was already correct this is a no-op set.
 */
final readonly class RlsTenantGuard
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function reassert(Tenant $tenant): void
    {
        // tenant-safe: infrastructure (re-establishes the tenant_id RLS policies read; this IS the tenant boundary, not a bypass)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
    }
}
