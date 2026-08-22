<?php

declare(strict_types=1);

namespace App\Identity\Application\Audit;

use App\Identity\Application\CurrentTenantProvider;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;

/**
 * #2976 / #2978 — the tenant an audit row is attributed to.
 *
 * `audit_logs` runs under FORCE ROW LEVEL SECURITY, and the GUC its policy
 * reads is set from {@see TenantContext} — by RlsContextListener on HTTP,
 * TenantRlsGucMiddleware in workers, TenantScopeBinder on console commands and
 * signed session-less routes. An audit writer that resolves the tenant from
 * anywhere else can disagree with the GUC, and the policy admits a row only
 * when the GUC is empty or matches: a NULL tenant against a set GUC satisfies
 * neither, so the write fails and takes the whole request or command with it.
 *
 * That is not hypothetical. {@see CurrentTenantProvider} reads the security
 * token and nothing else, so on every entry point without a principal it
 * returns null while the GUC names a tenant:
 *
 *   - the signed catalog-pull route answered 500 on `new row violates
 *     row-level security policy for table "audit_logs"` (#2976),
 *   - `pim:agent:start` died the same way once #2978 had given console
 *     commands a GUC at all — the audit writer had simply never been reached
 *     before, because the command failed earlier.
 *
 * Reading the context first makes the row and the policy agree by
 * construction. The provider stays as the fallback for flows that
 * authenticate WITHIN the request (login): there the context is still empty at
 * kernel.response, but so is the GUC, and the pre-context-safe policy admits
 * the row anyway.
 */
final readonly class AuditTenantResolver
{
    public function __construct(
        private TenantContext $tenantContext,
        private CurrentTenantProvider $tenantProvider,
    ) {
    }

    public function resolve(): ?Tenant
    {
        $bound = $this->tenantContext->get();
        if ($bound instanceof Tenant) {
            return $bound;
        }

        return $this->tenantProvider->getCurrent();
    }
}
