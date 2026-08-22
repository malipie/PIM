<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\DBAL\Connection;

/**
 * #2466 / #2978 — the single door for binding a tenant on an entry point that
 * has no security principal to derive one from.
 *
 * "Which tenant am I working on" is recorded in THREE places that must never
 * disagree:
 *
 *   1. the PHP-side {@see TenantContext} — listeners, provenance, services,
 *   2. the Doctrine tenant filter parameter ({@see TenantFilterConfigurator}),
 *   3. the Postgres GUC `app.current_tenant` (+ `app.is_super_admin`), which
 *      every RLS policy reads.
 *
 * When they diverge nothing throws in the obvious place: the app connects as
 * `pim_app` (NOBYPASSRLS) against tables under FORCE ROW LEVEL SECURITY, so an
 * unset GUC turns the strict policy `tenant_id = current_setting(…)::uuid` into
 * `tenant_id = NULL` — zero rows on read, rejection on write. The symptom lands
 * far from the cause, and it has landed four times: #2975 (thumbnails answered
 * 404 with the blobs present), #2976 (catalog PDF answered 500 on an audit
 * insert), #2977 (the agent reported an empty catalog), #2978 (console commands
 * failing, one of them reporting "no BYOK key configured" while the key was
 * there — the row was simply invisible).
 *
 * Two entry points bind the tenant automatically and do NOT need this class:
 * {@see \App\Identity\Infrastructure\Doctrine\RlsContextListener} for HTTP
 * requests carrying a principal, and
 * {@see \App\Shared\Infrastructure\Messenger\TenantRlsGucMiddleware} for
 * Messenger workers. Everything else — console commands, and the signed
 * session-less routes where an `<img>` tag or a partner's puller has no way to
 * send a token — goes through here. `RawTenantGucRule` (PHPStan) keeps it that
 * way by rejecting hand-rolled copies of the `set_config` pair, and
 * `TenantBindingMustUseBinderRule` rejects a console command that binds
 * {@see TenantContext} alone.
 *
 * release() clears all three again, so a command sweeping several tenants never
 * leaks the previous one into the next iteration.
 */
final readonly class TenantScopeBinder
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantFilterConfigurator $filterConfigurator,
        private Connection $connection,
    ) {
    }

    public function bind(Tenant $tenant): void
    {
        $this->tenantContext->set($tenant);
        $this->filterConfigurator->apply();

        // tenant-safe: infrastructure (establishes the tenant_id the RLS policies read; this IS the tenant boundary, not a bypass)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
        // tenant-safe: infrastructure (these entry points never run as super-admin; pin the bypass flag off in case the connection carried it)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    public function release(): void
    {
        $this->tenantContext->clear();
        $this->filterConfigurator->apply();

        // tenant-safe: infrastructure (resets the RLS tenant marker so the connection cannot leak the previous tenant)
        $this->connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
    }

    /**
     * Whether a tenant is already bound for this request / process.
     *
     * Lets a caller that must not override an existing scope — the signed
     * preview route, which keeps an authenticated caller's own tenant — ask
     * without reaching into {@see TenantContext} itself (which the PHPStan
     * gate discourages outside this class).
     */
    public function isBound(): bool
    {
        return $this->tenantContext->get() instanceof Tenant;
    }
}
