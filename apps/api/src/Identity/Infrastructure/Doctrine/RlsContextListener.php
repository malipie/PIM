<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\SuperAdmin\RlsBypass;
use App\Shared\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RBAC-P2-005 (#654) / AUD-002 (W1-1) — wires the Postgres session
 * variables that the RLS policies read.
 *
 * Per-request flow (kernel.request, priority 0 — after Symfony Security
 * has resolved the principal but before any controller / Doctrine work):
 *   1. Read current TenantContext (set by CurrentTenantProvider or
 *      RbacApiTokenAuthenticator earlier in the request lifecycle).
 *   2. Issue `set_config('app.current_tenant', '<uuid>', false)` so the
 *      RLS policy `tenant_id = current_setting(...)::uuid` evaluates.
 *   3. Cross-tenant Super Admin entry points set
 *      `app.is_super_admin = 'true'` via the same listener API
 *      (called explicitly from Phase 3 #677 break-glass paths).
 *
 * Session scope, NOT transaction scope (AUD-002 fix). The third argument
 * to `set_config` is `is_local`. The HTTP request path does NOT wrap its
 * queries in a single transaction — Doctrine/PDO runs in libpq autocommit
 * mode, so each statement is its own implicit transaction that commits
 * immediately. A transaction-local (`is_local = true`) GUC therefore
 * vanishes the instant the `set_config` statement autocommits, leaving
 * every subsequent query (and the kernel.response audit_logs INSERT) with
 * an unset tenant. Under FORCE ROW LEVEL SECURITY that denies every row /
 * fails every write — empirically confirmed: with FORCE on `audit_logs`
 * the login flow died with `new row violates row-level security policy
 * for table "audit_logs"` because the GUC was already gone by
 * kernel.response.
 *
 * Setting at session level (`is_local = false`) keeps the value for the
 * whole request regardless of transaction boundaries — the same choice
 * {@see \App\Shared\Infrastructure\Messenger\TenantRlsGucMiddleware} makes
 * for workers (which commit many chunks per message). FrankenPHP worker
 * mode reuses the DBAL connection across HTTP requests, so the value MUST
 * be cleared when the request ends or it would leak into the next
 * request's pre-authentication window — {@see onKernelTerminate} resets it
 * at priority -256 (after RequestTenantSubscriber's -255 context clear and
 * after the audit write has run).
 *
 * Bypass safety: if no tenant is resolved (unauthenticated request to
 * /api/docs, /api/health, etc.) the variable is reset to empty so any
 * accidental query through the connection returns 0 rows from the
 * RLS-protected DOMAIN tables. Auth tables (users, refresh_tokens, …) are
 * read during the firewall phase before this listener runs and carry a
 * pre-context-safe policy that allows reads while the GUC is empty — see
 * the W1-1 migration.
 */
final class RlsContextListener implements RlsBypass
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            // No tenant resolved — reset the session variable so any
            // accidental query through the connection returns 0 rows from
            // the RLS-protected domain tables (and so a pooled FrankenPHP
            // connection never carries the previous request's tenant).
            // tenant-safe: infrastructure (sets the Postgres session variable that RLS policies read; does not query tenant-scoped data)
            $this->connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
            // tenant-safe: infrastructure (clears super-admin session flag)
            $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");

            return;
        }

        // tenant-safe: infrastructure (establishes the tenant_id RLS policies use; this IS the tenant boundary, not a bypass)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
        // tenant-safe: infrastructure (clears super-admin session flag for every regular request)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    /**
     * Clears the session GUC at the very end of the request. Essential under
     * FrankenPHP worker mode, where the DBAL connection survives between
     * requests: without this reset the next request would start with the
     * previous tenant's `app.current_tenant` still set during its
     * pre-authentication firewall phase. Priority -256 runs after
     * RequestTenantSubscriber clears TenantContext (-255) and after the
     * kernel.response audit write.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -256)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // tenant-safe: infrastructure (resets the RLS tenant marker so a pooled FrankenPHP connection cannot leak the tenant into the next request)
        $this->connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
        // tenant-safe: infrastructure (resets the super-admin bypass flag for the pooled connection)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    /**
     * Public entry point for the Phase 3 Super Admin bypass flow (#677).
     * Sets `app.is_super_admin = 'true'` at session scope for the remainder
     * of the current request (reset on terminate, as above).
     */
    public function enableSuperAdminBypass(): void
    {
        // tenant-safe: infrastructure (Super Admin bypass — audited via Phase 3 #676 listener with cross_tenant_access=true)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'true', false)");
    }

    /**
     * #2876 — the other half of the bypass, so a caller can hand the
     * privilege back the moment it is done instead of waiting for
     * `kernel.terminate`. Without it, a cross-tenant block that finishes
     * early would leave the rest of the request able to write any tenant's
     * rows.
     */
    public function disableSuperAdminBypass(): void
    {
        // tenant-safe: infrastructure (drops the bypass; the request returns to plain tenant isolation)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }
}
