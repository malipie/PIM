<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Application\BuiltInProductRelationAttributesSeeder;
use App\Catalog\Application\BuiltInSmartFilterPresetsSeeder;
use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Application\DefaultMenuSeeder;
use App\Catalog\Application\DemoCatalogSeeder;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Channel\Domain\Entity\Locale;
use App\Channel\Domain\Entity\TenantLocale;
use App\DataFixtures\Identity\PrdPermissionFixtures;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\Permission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\PermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sprint 0 demo dataset, post-ADR-009 + post-#33.
 *
 * Two tenants ("demo" and "acme") each with one admin user, the three
 * built-in ObjectTypes (product / category / asset) seeded via
 * BuiltInObjectTypeSeeder, and three demo product objects (`CatalogObject`
 * with `kind='product'`). The legacy `Product` entity was dropped
 * alongside #33's data migration; demo products now live in `objects`
 * with their data folded into `attributes_indexed` per ADR-006 hybrid.
 */
class AppFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * AppFixtures runs after `PrdPermissionFixtures` so the PRD §3.2
     * permission codes exist when `SeedTenantPrdRolesService` resolves
     * them while attaching the 9 tenant-level role templates.
     *
     * @return array<int, class-string<\Doctrine\Common\DataFixtures\FixtureInterface>>
     */
    public function getDependencies(): array
    {
        return [PrdPermissionFixtures::class];
    }

    /**
     * Dev/test fixture password. An online/demo deployment can override via
     * PIM_SEED_ADMIN_PASSWORD; the 'changeme' fallback keeps ~60 PHPUnit and
     * Playwright logins working locally and in CI.
     */
    private const string DEFAULT_ADMIN_PASSWORD = 'changeme';

    private static function adminPassword(): string
    {
        $env = $_SERVER['PIM_SEED_ADMIN_PASSWORD'] ?? $_ENV['PIM_SEED_ADMIN_PASSWORD'] ?? null;

        return \is_string($env) && '' !== $env ? $env : self::DEFAULT_ADMIN_PASSWORD;
    }

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RbacSeeder $rbacSeeder,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly PermissionRepositoryInterface $permissionRepository,
        private readonly SeedTenantPrdRolesService $tenantPrdRolesSeeder,
        private readonly BuiltInObjectTypeSeeder $builtInSeeder,
        private readonly BuiltInSystemAttributesSeeder $systemAttributesSeeder,
        private readonly BuiltInProductRelationAttributesSeeder $productRelationAttributesSeeder,
        private readonly ObjectTypeRepositoryInterface $objectTypeRepository,
        private readonly DemoCatalogSeeder $demoCatalogSeeder,
        private readonly DefaultMenuSeeder $defaultMenuSeeder,
        private readonly BuiltInSmartFilterPresetsSeeder $smartFilterPresetsSeeder,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Global infrastructure: locales + currencies. The seed migration
        // (Version20260429064833 + #869) inserts these on fresh schema, but
        // `doctrine:fixtures:load` purges the database first — re-seed
        // the 14 popular CEE+DACH locales here so post-fixtures DB has the
        // same baseline as a migrated-from-scratch schema (with the popular
        // subset matching the `is_popular=true` flag in the catalog seed).
        $localesByCode = [];
        foreach (self::POPULAR_LOCALES as $entry) {
            $locale = new Locale(
                $entry['code'],
                $entry['displayName']['pl'],
                null,
                $entry['language'],
                $entry['region'],
                $entry['displayName'],
                true,
            );
            $manager->persist($locale);
            $localesByCode[$entry['code']] = $locale;
        }

        $tenants = [
            new Tenant('demo', 'Demo Tenant'),
            new Tenant('acme', 'Acme Industries'),
        ];

        foreach ($tenants as $tenant) {
            $manager->persist($tenant);
        }

        $manager->flush();

        // Seed the four built-in global roles before persisting users so the
        // admin fixture can attach the super_admin role through the M2M graph
        // rather than the legacy `roles JSON` column.
        $this->rbacSeeder->seed();

        $superAdmin = $this->roleRepository->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin, 'RbacSeeder must create the super_admin role.');
        $platformOperator = $this->roleRepository->findGlobalByCode(RbacMatrix::ROLE_PLATFORM_OPERATOR);
        \assert(null !== $platformOperator, 'RbacSeeder must create the platform_operator role.');

        // Grant super_admin every tenant-scoped PRD §3.2 permission so a
        // tenant Owner can drive the Settings UI without a Phase 6 retrofit
        // gap. AUD-003 (#1575): the cross-tenant `platform.*` block is
        // deliberately withheld here and granted only to platform_operator.
        $this->grantAllPermissionsToSuperAdmin($superAdmin);
        $this->grantPlatformPermissionsToOperator($platformOperator);

        // Per-tenant: built-in ObjectTypes (#33) → admin user → demo
        // catalog rows. The seeder is idempotent — `pim:db:reset` re-runs
        // are safe.
        foreach ($tenants as $tenant) {
            $this->builtInSeeder->seed($tenant);
            // System audit attributes are seeded as platform-owned Attribute
            // rows only. Visibility is explicit modeling configuration;
            // no AttributeGroup is auto-created or auto-attached.
            $this->systemAttributesSeeder->seed($tenant);
            // ADR-014 / MOD-02 (#894): seed the 5 built-in `relation`
            // attributes on Product ObjectType + the "Powiązania" group
            // that hosts them. Replaces BuiltInAssociationTypeSeeder.
            $this->productRelationAttributesSeeder->seed($tenant);
            // UX-02 — the "Multimedia" AttributeGroup is no longer seeded.
            // Multimedia is now a capability flag (`ObjectType.hasMultimedia`)
            // driving a hardcoded conditional tab, not a group of attributes.
            // VIEW-08 (#427): seed the default sidebar layout (8 items
            // matching the legacy hard-coded sidebar minus Services).
            $this->defaultMenuSeeder->seed($tenant);
            // RBAC-P1-007 (#646) — seed the 9 PRD-PIM-rbac §3.2 tenant-level
            // role templates (tenant_owner / admin / catalog_manager / …).
            // Without this, the demo admin lands without `settings.*.manage`
            // PRD codes and the Settings → Users / Roles UI 403s.
            $this->tenantPrdRolesSeeder->seed($tenant);
        }

        // VIEW-09 (#535): 5 built-in Smart Filter Presets are system-shipped
        // (`tenant_id IS NULL`), shared across every tenant. The migration
        // inlines these on fresh schema, but fixtures-load purges the
        // database first — re-seed here so the post-fixtures DB matches.
        $this->smartFilterPresetsSeeder->seed();

        // Locales feature (#869, LOC-01): seed each tenant's activated locales.
        // pl_PL = default + mandatory, en_US = mandatory with fallback=pl_PL.
        // Mirrors the channel/tenant configuration #705 used to keep on
        // `tenants.locales` array; LOC-02 (#870) will migrate that legacy
        // column into these rows for any production tenant that has data.
        $plPL = $localesByCode['pl_PL'];
        $enUS = $localesByCode['en_US'];
        foreach ($tenants as $tenant) {
            $manager->persist(new TenantLocale($plPL, true, true, null, 0, $tenant));
            $manager->persist(new TenantLocale($enUS, false, true, $plPL, 1, $tenant));
        }
        $manager->flush();

        $admins = [
            'demo' => 'admin@demo.localhost',
            'acme' => 'admin@acme.localhost',
        ];
        foreach ($tenants as $tenant) {
            $email = $admins[$tenant->getCode()];
            $stub = new User($tenant, $email, '', []);
            $admin = new User(
                $tenant,
                $email,
                $this->passwordHasher->hashPassword($stub, self::adminPassword()),
                [],
            );
            $admin->addRole($superAdmin);
            // Per-tenant `tenant_owner` role seeded by
            // SeedTenantPrdRolesService above. Attaching it here gives
            // the demo admin every PRD §3.2 tenant-level permission
            // (`settings.users.manage`, `settings.roles.manage`,
            // `products.bulk_operations`, etc.) on top of the legacy
            // RbacMatrix codes that ride with super_admin. Two roles
            // are the cleanest way to surface both permission graphs
            // until Phase 6 consolidates them.
            $tenantOwner = $this->roleRepository->findByCode('tenant_owner', $tenant);
            \assert(null !== $tenantOwner, 'SeedTenantPrdRolesService must create tenant_owner per tenant.');
            $admin->addRole($tenantOwner);
            $manager->persist($admin);
        }

        // #2555 — a dedicated non-owner REVIEWER persona holding the built-in
        // `approver` role. Review tasks route to the per-ObjectType definition
        // reviewer when one is enabled, otherwise to `approver` (the fallback);
        // without any user holding it, review tasks are visible only to Owners
        // via the `workflow.approve_reject` override. Seeding this reviewer
        // makes the flow demonstrable end-to-end on a fresh DB via the fallback
        // path. We deliberately do NOT seed an enabled product WorkflowDefinition
        // here: an enabled product definition in the shared fixtures would leak
        // into the product-driving workflow e2e specs (wfl-p5-03 / wfl-p6-02),
        // which build/drive their own product definitions. Per-ObjectType
        // reviewers are configured through the (now unhidden) flow-settings UI.
        $demoTenant = null;
        foreach ($tenants as $tenant) {
            if ('demo' === $tenant->getCode()) {
                $demoTenant = $tenant;
                break;
            }
        }
        \assert(null !== $demoTenant, 'The demo tenant must exist.');

        $reviewerRole = $this->roleRepository->findByCode('approver', $demoTenant);
        \assert(null !== $reviewerRole, 'SeedTenantPrdRolesService must create approver per tenant.');
        $reviewerEmail = 'reviewer@demo.localhost';
        $reviewerStub = new User($demoTenant, $reviewerEmail, '', []);
        $reviewer = new User(
            $demoTenant,
            $reviewerEmail,
            $this->passwordHasher->hashPassword($reviewerStub, self::adminPassword()),
            [],
        );
        $reviewer->addRole($reviewerRole);
        $manager->persist($reviewer);

        // AUD-003 (#1575): the platform operator (operator panel + break-glass)
        // is a SEPARATE principal from any tenant Owner. It holds ONLY the
        // global `platform_operator` role (the `platform.*` grants) and no
        // tenant-scoped role, so the cross-tenant `/api/admin/tenants/*` panel
        // is reachable for break-glass / onboarding but NOT by an Owner.
        // Every User needs tenant_id NOT NULL; it is parked in the demo tenant
        // but carries no tenant-scoped authority there.
        $operatorEmail = 'platform-operator@cortex.localhost';
        $operatorStub = new User($tenants[0], $operatorEmail, '', []);
        $operator = new User(
            $tenants[0],
            $operatorEmail,
            $this->passwordHasher->hashPassword($operatorStub, self::adminPassword()),
            [],
        );
        $operator->addRole($platformOperator);
        $manager->persist($operator);

        $manager->flush();

        // #1259 — seed one real demo channel (Allegro) for the demo tenant so
        // the product card's channel picker has actual data after a DB reset,
        // instead of falling back to a hardcoded mock list on the frontend.
        // Built-in ObjectTypes already exist (builtInSeeder above), so the
        // ChannelCreated subscriber provisions default publication profiles.
        $demoTenant = $tenants[0];
        \assert('demo' === $demoTenant->getCode());
        $allegro = new Channel('allegro', 'Allegro');
        $allegro->assignTenant($demoTenant);
        $manager->persist($allegro);
        $manager->flush();

        // Full ADR-009 dataset (#40). 100 products + 5 categories with own
        // user-defined attributes + 10 assets + ~19 attributes spanning all
        // 10 AttributeType cases. Passing the Allegro channel id seeds
        // per-channel price overrides on the first few products (#1259).
        $this->demoCatalogSeeder->seed($demoTenant, $allegro->getId());

        // Acme tenant: minimal smoke dataset (3 SKUs, no attribute graph).
        // Existing isolation tests + admin e2e probes assume the legacy shape;
        // promoting acme to the full graph is out of scope for #40.
        $this->tenantContext->set($tenants[1]);
        $acmeProductType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Product, $tenants[1]);
        \assert(null !== $acmeProductType, 'BuiltInObjectTypeSeeder must seed the product ObjectType.');
        foreach ([
            ['ACME-001', 'Acme Widget',   'Acme'],
            ['ACME-002', 'Acme Gadget',   'Acme'],
            ['ACME-003', 'Acme Sprocket', 'Acme'],
        ] as [$sku, $name, $brand]) {
            $object = new CatalogObject($acmeProductType, $sku);
            $object->forceStatus(CatalogObject::STATUS_PUBLISHED);
            $object->updateAttributeIndex([
                'sku' => $sku,
                'name' => $name,
                'brand' => $brand,
                'description' => 'Seeded demo product for tenant acme.',
            ]);
            $manager->persist($object);
        }
        $manager->flush();

        $this->tenantContext->clear();
    }

    /**
     * Attach every permission row (legacy RbacMatrix + PRD §3.2 codes
     * loaded by PrdPermissionFixtures) to the global super_admin role so
     * the demo admin user holds the union of both permission graphs —
     * EXCEPT the cross-tenant `platform.*` block.
     *
     * AUD-003 (#1575): `super_admin` is the role every tenant Owner holds.
     * Granting it `platform.tenants.manage` / `platform.break_glass_recovery`
     * lets an Owner recon + suspend + delete competitor tenants. Those
     * grants belong exclusively to the dedicated `platform_operator` role
     * ({@see grantPlatformPermissionsToOperator}), never to a tenant Owner.
     *
     * Idempotent: only the permissions not yet on the role are added,
     * so re-running fixtures (or extending PRD codes later) keeps the
     * grant complete without duplicates.
     */
    private function grantAllPermissionsToSuperAdmin(\App\Identity\Domain\Entity\Role $superAdmin): void
    {
        $existingCodes = [];
        foreach ($superAdmin->getPermissions() as $existing) {
            $existingCodes[] = $existing->getCode();
        }

        foreach ($this->permissionRepository->findAllOrdered() as $permission) {
            if (str_starts_with($permission->getCode(), 'platform.')) {
                continue; // platform-scope grants are operator-only (AUD-003)
            }
            if (\in_array($permission->getCode(), $existingCodes, true)) {
                continue;
            }
            $superAdmin->getPermissions()->add($permission);
        }
    }

    /**
     * AUD-003 (#1575): grant the four cross-tenant `platform.*` permissions
     * to the global `platform_operator` role. RbacSeeder already wires this
     * via RbacMatrix, but PrdPermissionFixtures may have created the
     * `platform.*` rows with their own UUIDs first; re-attach idempotently
     * so the role holds the canonical rows regardless of seed order.
     */
    private function grantPlatformPermissionsToOperator(\App\Identity\Domain\Entity\Role $operator): void
    {
        $existingCodes = [];
        foreach ($operator->getPermissions() as $existing) {
            $existingCodes[] = $existing->getCode();
        }

        foreach ($this->permissionRepository->findAllOrdered() as $permission) {
            if (!str_starts_with($permission->getCode(), 'platform.')) {
                continue;
            }
            if (\in_array($permission->getCode(), $existingCodes, true)) {
                continue;
            }
            $operator->getPermissions()->add($permission);
        }
    }

    /**
     * @var list<array{code:string,language:string,region:string,displayName:array<string,string>}>
     */
    private const POPULAR_LOCALES = [
        ['code' => 'pl_PL', 'language' => 'pl', 'region' => 'PL', 'displayName' => ['pl' => 'Polski (Polska)', 'en' => 'Polish (Poland)']],
        ['code' => 'en_US', 'language' => 'en', 'region' => 'US', 'displayName' => ['pl' => 'Angielski (USA)', 'en' => 'English (United States)']],
        ['code' => 'en_GB', 'language' => 'en', 'region' => 'GB', 'displayName' => ['pl' => 'Angielski (Wielka Brytania)', 'en' => 'English (United Kingdom)']],
        ['code' => 'de_DE', 'language' => 'de', 'region' => 'DE', 'displayName' => ['pl' => 'Niemiecki (Niemcy)', 'en' => 'German (Germany)']],
        ['code' => 'de_AT', 'language' => 'de', 'region' => 'AT', 'displayName' => ['pl' => 'Niemiecki (Austria)', 'en' => 'German (Austria)']],
        ['code' => 'de_CH', 'language' => 'de', 'region' => 'CH', 'displayName' => ['pl' => 'Niemiecki (Szwajcaria)', 'en' => 'German (Switzerland)']],
        ['code' => 'fr_FR', 'language' => 'fr', 'region' => 'FR', 'displayName' => ['pl' => 'Francuski (Francja)', 'en' => 'French (France)']],
        ['code' => 'it_IT', 'language' => 'it', 'region' => 'IT', 'displayName' => ['pl' => 'Włoski (Włochy)', 'en' => 'Italian (Italy)']],
        ['code' => 'es_ES', 'language' => 'es', 'region' => 'ES', 'displayName' => ['pl' => 'Hiszpański (Hiszpania)', 'en' => 'Spanish (Spain)']],
        ['code' => 'cs_CZ', 'language' => 'cs', 'region' => 'CZ', 'displayName' => ['pl' => 'Czeski (Czechy)', 'en' => 'Czech (Czechia)']],
        ['code' => 'sk_SK', 'language' => 'sk', 'region' => 'SK', 'displayName' => ['pl' => 'Słowacki (Słowacja)', 'en' => 'Slovak (Slovakia)']],
        ['code' => 'hu_HU', 'language' => 'hu', 'region' => 'HU', 'displayName' => ['pl' => 'Węgierski (Węgry)', 'en' => 'Hungarian (Hungary)']],
        ['code' => 'ro_RO', 'language' => 'ro', 'region' => 'RO', 'displayName' => ['pl' => 'Rumuński (Rumunia)', 'en' => 'Romanian (Romania)']],
        ['code' => 'nl_NL', 'language' => 'nl', 'region' => 'NL', 'displayName' => ['pl' => 'Holenderski (Holandia)', 'en' => 'Dutch (Netherlands)']],
    ];
}
