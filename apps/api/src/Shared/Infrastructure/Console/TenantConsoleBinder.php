<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\DBAL\Connection;

/**
 * #2466 — binds the full tenant context for a console (maintenance) command.
 *
 * The RLS policies on tenant-scoped tables read the Postgres GUC
 * `app.current_tenant`, which is established by RlsContextListener on HTTP
 * requests and by TenantRlsGucMiddleware in messenger workers. Console
 * commands had neither, so with the app connecting as `pim_app` every
 * SELECT against `objects` returned zero rows and maintenance commands
 * (pim:search:reindex, pim:catalog:recalculate-completeness) silently
 * processed nothing.
 *
 * bind() wires all three layers for one tenant:
 *   1. PHP-side {@see TenantContext} (listeners / provenance / services),
 *   2. the Doctrine tenant filter parameter ({@see TenantFilterConfigurator}),
 *   3. the Postgres RLS GUC pair.
 *
 * release() clears them again so a command iterating several tenants never
 * leaks the previous tenant into the next loop iteration.
 */
final readonly class TenantConsoleBinder
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

        // tenant-safe: infrastructure (establishes the tenant_id the RLS policies read for a console command; this IS the tenant boundary, not a bypass)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
        // tenant-safe: infrastructure (maintenance commands never run as super-admin; pin the bypass flag off in case the connection carried it)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    public function release(): void
    {
        $this->tenantContext->clear();
        $this->filterConfigurator->apply();

        // tenant-safe: infrastructure (resets the RLS tenant marker so the connection cannot leak the previous tenant)
        $this->connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
    }
}
