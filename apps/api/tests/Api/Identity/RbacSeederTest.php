<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\Permission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Coverage for #27 (0.2.4) — RBAC seeder + getRoles() merge.
 *
 * Hits a real Postgres so the unique constraints from #24 actively guard
 * idempotency: a buggy seeder duplicating rows would fail at flush.
 */
final class RbacSeederTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    #[Test]
    public function seedsOnlyThePlatformRoles(): void
    {
        $this->seeder()->seed();

        $roles = $this->roleRepository();

        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        $platformOperator = $roles->findGlobalByCode(RbacMatrix::ROLE_PLATFORM_OPERATOR);

        self::assertNotNull($superAdmin, 'super_admin must exist after seeding.');
        self::assertNotNull($platformOperator, 'platform_operator must exist after seeding.');

        // #2837 — tenant-facing roles belong to the per-tenant PRD
        // templates. Seeding them globally as well produced two rows per
        // code, identical in the panel and different in what they granted.
        foreach ([
            RbacMatrix::ROLE_CATALOG_MANAGER,
            RbacMatrix::ROLE_INTEGRATION_MANAGER,
            RbacMatrix::ROLE_VIEWER,
        ] as $tenantRoleCode) {
            self::assertNull(
                $roles->findGlobalByCode($tenantRoleCode),
                \sprintf('%s is a tenant role — it must not be seeded globally.', $tenantRoleCode),
            );
        }

        // AUD-003 (#1575): super_admin gets every legacy (resource, action)
        // pair but NONE of the cross-tenant `platform.*` codes — those are
        // exclusive to platform_operator so a tenant Owner (who holds
        // super_admin) cannot manage other tenants.
        $superAdminCodes = array_map(static fn (Permission $p): string => $p->getCode(), $superAdmin->getPermissions()->toArray());
        foreach ($superAdminCodes as $code) {
            self::assertStringStartsNotWith('platform.', $code, 'super_admin must not hold any platform.* permission.');
        }
        self::assertContains('tenant.admin', $superAdminCodes);

        // platform_operator holds exactly the four cross-tenant grants.
        $platformCodes = array_map(static fn (Permission $p): string => $p->getCode(), $platformOperator->getPermissions()->toArray());
        sort($platformCodes);
        self::assertSame([
            'platform.audit.view_all',
            'platform.break_glass_recovery',
            'platform.tenants.list',
            'platform.tenants.manage',
        ], $platformCodes);
    }

    #[Test]
    public function rerunningTheSeederIsIdempotent(): void
    {
        $first = $this->seeder()->seed();
        $second = $this->seeder()->seed();

        self::assertGreaterThan(0, $first->permissionsCreated, 'First run must create permission rows.');
        self::assertGreaterThan(0, $first->rolesCreated, 'First run must create role rows.');

        self::assertTrue($second->isNoOp(), \sprintf(
            'Second run must be a no-op: created %d/%d permissions/roles, updated %d roles.',
            $second->permissionsCreated,
            $second->rolesCreated,
            $second->rolesUpdated,
        ));
    }

    #[Test]
    public function getRolesMergesM2mGraphWithLegacyJsonColumn(): void
    {
        $this->seeder()->seed();
        $em = $this->em();

        $tenant = new Tenant('alpha', 'Alpha');
        $em->persist($tenant);
        $em->flush();

        // #2837 — a tenant role, seeded per tenant from the PRD templates.
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $catalogManager = $this->roleRepository()->findByCode(RbacMatrix::ROLE_CATALOG_MANAGER, $tenant);
        self::assertNotNull($catalogManager);

        $user = new User($tenant, 'kasia@alpha.test', '', ['ROLE_LEGACY']);
        $user->addRole($catalogManager);
        $em->persist($user);
        $em->flush();

        $em->clear();

        /** @var User $reloaded */
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'kasia@alpha.test']);
        $resolved = $reloaded->getRoles();

        // ROLE_USER is implicit, ROLE_LEGACY comes from the JSON column,
        // ROLE_CATALOG_MANAGER comes from the role assignment (ADR-0034).
        self::assertContains('ROLE_USER', $resolved);
        self::assertContains('ROLE_LEGACY', $resolved);
        self::assertContains('ROLE_CATALOG_MANAGER', $resolved);
        self::assertSame(\count($resolved), \count(array_unique($resolved)), 'getRoles() must return a deduplicated list.');
    }

    private function seeder(): RbacSeeder
    {
        return self::getContainer()->get(RbacSeeder::class);
    }

    private function roleRepository(): RoleRepositoryInterface
    {
        return self::getContainer()->get(RoleRepositoryInterface::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
