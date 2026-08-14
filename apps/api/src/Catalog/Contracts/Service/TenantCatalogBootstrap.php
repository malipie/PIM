<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Service;

use App\Shared\Domain\Tenant;

/**
 * #2875 — everything a brand-new tenant needs before anyone can use its
 * catalogue: the built-in ObjectTypes (ADR-009 Product / Category / Asset /
 * Brand), the platform-owned system attributes, the Product relation
 * attributes, and the default sidebar layout.
 *
 * The seam exists because tenant provisioning lives in Identity
 * (`SuperAdminTenantWriteController`) while all of the above is Catalog's
 * business, and Deptrac lets `Identity_Internals` reach `Catalog_Contracts`
 * only (ADR-0013).
 *
 * Until this port existed, that chain ran **only** in `AppFixtures` — so a
 * tenant created through `pim:db:reset --with-fixtures` was complete, and a
 * tenant created through the admin UI came up with no ObjectTypes at all.
 * Its owner opened Products and was told the built-in product type was
 * missing and to run the catalog seeder.
 *
 * Implementations must be idempotent: `pim:db:reset` re-runs the same chain,
 * and the repair command for tenants provisioned before this fix runs it a
 * second time over rows that already exist.
 */
interface TenantCatalogBootstrap
{
    public function bootstrap(Tenant $tenant): void;
}
