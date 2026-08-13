<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\RoleAttributePermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleAttributePermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AUD-024 / W1-12 — per-attribute permission grants must not become an
 * escalation vector
 * ({@see \App\Identity\Presentation\Controller\RoleAttributePermissionsController}).
 *
 * PRD-PIM-rbac §3.5: a role may carry a 3-state (view / edit / restricted)
 * override per attribute. The PUT replace endpoint must refuse to widen a
 * grant beyond what the caller's tenant/role may legitimately set:
 *
 *  - non-admin (Catalog Manager, no `user.admin`) → 403;
 *  - PUT against a role owned by another tenant → 404 (no existence leak);
 *  - an `attribute_id` belonging to another tenant → 400 (the catalogue
 *    reader treats cross-tenant + unknown identically — the grant never
 *    lands, so a role cannot be handed an override on a foreign attribute);
 *  - an out-of-range `permission_level` (a state the engine cannot
 *    enforce) → 400;
 *  - admin + own-tenant attribute → 200, override persisted (proves the
 *    rejections above are policy, not a dead endpoint).
 *
 * Status codes only (RFC 7807 detail differs debug/non-debug).
 */
final class RoleAttributePermissionsControllerTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_A_CODE = 'demo';
    private const string TENANT_B_CODE = 'other';

    private const string ADMIN_A_EMAIL = 'admin@demo.localhost';
    private const string CATALOG_A_EMAIL = 'catalog@demo.localhost';

    private string $customAId;
    private string $customBId;
    private string $attributeAId;
    private string $attributeBId;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenantA = new Tenant(self::TENANT_A_CODE, 'Demo Tenant');
        $tenantB = new Tenant(self::TENANT_B_CODE, 'Other Tenant');
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        // #2837 — tenant roles come from the per-tenant PRD templates, so
        // they exist only after the tenant does.
        $prdRoles = self::getContainer()->get(SeedTenantPrdRolesService::class);
        $prdRoles->seed($tenantA);
        $prdRoles->seed($tenantB);
        $catalogManager = $roles->findByCode(RbacMatrix::ROLE_CATALOG_MANAGER, $tenantA);
        \assert(null !== $catalogManager);

        $customA = new Role('custom_a', 'Custom Role A', $tenantA);
        $em->persist($customA);
        $customB = new Role('custom_b', 'Custom Role B', $tenantB);
        $em->persist($customB);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $adminStub = new User($tenantA, self::ADMIN_A_EMAIL, '');
        $admin = new User($tenantA, self::ADMIN_A_EMAIL, $hasher->hashPassword($adminStub, 'changeme'));
        $admin->addRole($superAdmin);
        $em->persist($admin);

        $catalogStub = new User($tenantA, self::CATALOG_A_EMAIL, '');
        $catalog = new User($tenantA, self::CATALOG_A_EMAIL, $hasher->hashPassword($catalogStub, 'changeme'));
        $catalog->addRole($catalogManager);
        $em->persist($catalog);

        $em->flush();

        $this->customAId = $customA->getId()->toRfc4122();
        $this->customBId = $customB->getId()->toRfc4122();

        // Real attributes, one per tenant, persisted under the matching
        // tenant context so TenantAssignmentListener stamps tenant_id.
        $this->attributeAId = $this->seedAttribute($tenantA, 'price_a', AttributeType::Number)->toRfc4122();
        $this->attributeBId = $this->seedAttribute($tenantB, 'price_b', AttributeType::Number)->toRfc4122();

        self::getContainer()->get(TenantContext::class)->clear();
    }

    // --- Escalation: non-admin denied (403) -------------------------------

    #[Test]
    public function nonAdminCannotReplaceAttributePermissions(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('PUT', '/api/roles/'.$this->customAId.'/attribute-permissions', [
            'json' => ['attribute_permissions' => [
                ['attribute_id' => $this->attributeAId, 'permission_level' => 'edit'],
            ]],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    // --- Cross-tenant role (404) ------------------------------------------

    #[Test]
    public function adminCannotReplaceForeignTenantRolePermissions(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PUT', '/api/roles/'.$this->customBId.'/attribute-permissions', [
            'json' => ['attribute_permissions' => [
                ['attribute_id' => $this->attributeAId, 'permission_level' => 'edit'],
            ]],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // --- Escalation: grant cannot reference a foreign attribute (400) -----

    #[Test]
    public function adminCannotGrantOverrideOnForeignTenantAttribute(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PUT', '/api/roles/'.$this->customAId.'/attribute-permissions', [
            'json' => ['attribute_permissions' => [
                // attribute_b belongs to tenant B — must not be grantable
                // on a tenant A role.
                ['attribute_id' => $this->attributeBId, 'permission_level' => 'edit'],
            ]],
        ]);

        self::assertResponseStatusCodeSame(400);

        // The foreign-attribute override must never have been written.
        $this->em()->clear();
        $overrides = self::getContainer()->get(RoleAttributePermissionRepositoryInterface::class)
            ->findByRole(Uuid::fromString($this->customAId));
        self::assertCount(0, $overrides, 'A rejected cross-tenant grant must leave no override row.');
    }

    #[Test]
    public function adminCannotGrantOverrideOnUnknownAttribute(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PUT', '/api/roles/'.$this->customAId.'/attribute-permissions', [
            'json' => ['attribute_permissions' => [
                ['attribute_id' => Uuid::v7()->toRfc4122(), 'permission_level' => 'view'],
            ]],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // --- Escalation: only enforceable levels accepted (400) ---------------

    #[Test]
    public function adminCannotGrantUnknownPermissionLevel(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PUT', '/api/roles/'.$this->customAId.'/attribute-permissions', [
            'json' => ['attribute_permissions' => [
                // 'admin' is not one of view|edit|restricted — a level the
                // resolver cannot enforce must be refused.
                ['attribute_id' => $this->attributeAId, 'permission_level' => 'admin'],
            ]],
        ]);

        self::assertResponseStatusCodeSame(400);

        $this->em()->clear();
        $overrides = self::getContainer()->get(RoleAttributePermissionRepositoryInterface::class)
            ->findByRole(Uuid::fromString($this->customAId));
        self::assertCount(0, $overrides, 'An invalid permission level must leave no override row.');
    }

    // --- Happy path: admin + own attribute (200) --------------------------

    #[Test]
    public function adminCanReplaceOwnTenantAttributePermissions(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PUT', '/api/roles/'.$this->customAId.'/attribute-permissions', [
            'json' => ['attribute_permissions' => [
                ['attribute_id' => $this->attributeAId, 'permission_level' => RoleAttributePermission::LEVEL_VIEW],
            ]],
        ]);

        self::assertResponseStatusCodeSame(200);

        $this->em()->clear();
        $overrides = self::getContainer()->get(RoleAttributePermissionRepositoryInterface::class)
            ->findByRole(Uuid::fromString($this->customAId));
        self::assertCount(1, $overrides, 'A valid own-tenant grant must persist exactly one override.');
    }

    private function seedAttribute(Tenant $tenant, string $code, AttributeType $type): Uuid
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $attribute = new Attribute($code, ['en' => ucfirst($code)], $type);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($attribute);

        return $attribute->getId();
    }

    private function clientFor(string $email): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
