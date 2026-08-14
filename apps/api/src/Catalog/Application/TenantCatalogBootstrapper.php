<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\Service\TenantCatalogBootstrap;
use App\Shared\Domain\Tenant;

/**
 * #2875 — the per-tenant catalogue bootstrap, in one place.
 *
 * The order matters and is the same one `AppFixtures` has always used:
 * ObjectTypes first because everything below attaches to them, then the
 * platform-owned system attributes, then the Product relation attributes
 * (ADR-014 / #894 — they need the Product type to exist), then the default
 * sidebar layout (VIEW-08 / #427), which references the types by id.
 *
 * Extracted so provisioning and fixtures cannot drift: before this, the
 * chain lived only inside `AppFixtures::load`, and
 * `SuperAdminTenantWriteController` — the path an operator actually uses —
 * seeded roles and nothing else. Any future step belongs here, not in one
 * of the two callers.
 */
final readonly class TenantCatalogBootstrapper implements TenantCatalogBootstrap
{
    public function __construct(
        private BuiltInObjectTypeSeeder $objectTypes,
        private BuiltInSystemAttributesSeeder $systemAttributes,
        private BuiltInProductRelationAttributesSeeder $relationAttributes,
        private DefaultMenuSeeder $defaultMenu,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->objectTypes->seed($tenant);
        // Platform-owned Attribute rows only — visibility stays explicit
        // modeling configuration, no AttributeGroup is auto-attached.
        $this->systemAttributes->seed($tenant);
        // ADR-014 / MOD-02 (#894) — the five built-in `relation` attributes
        // on Product plus the "Powiązania" group that hosts them.
        $this->relationAttributes->seed($tenant);
        // VIEW-08 (#427) — without this the tenant has no menu rows, so
        // Settings → Menu cannot show anything to configure and a freshly
        // added ObjectType has nowhere to be added to.
        $this->defaultMenu->seed($tenant);
    }
}
