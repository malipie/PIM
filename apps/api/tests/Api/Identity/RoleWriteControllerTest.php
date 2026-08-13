<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AUD-024 / W1-12 — role-escalation coverage for the write surface
 * ({@see \App\Identity\Presentation\Controller\RoleWriteController}).
 *
 * Before this test the only role coverage was the GET list
 * ({@see RolesListControllerTest}); nothing asserted that a non-admin is
 * denied write access or that a role belonging to another tenant cannot
 * be mutated. These are the privilege-escalation invariants:
 *
 *  - non-admin (Catalog Manager, no `user.admin`) → 403 on
 *    POST / PATCH / DELETE /api/roles;
 *  - PATCH / DELETE of a custom role owned by another tenant → 404
 *    (existence is NOT leaked — same shape as a missing role, per the
 *    TenantFilter / RLS pattern);
 *  - admin (Super Admin) happy-path → 201 / 200 / 204 so the 403s above
 *    are proven to be authorization, not a broken endpoint.
 *
 * Asserts status codes only (the RFC 7807 detail differs between
 * debug/non-debug, so CI would diverge on body strings).
 */
final class RoleWriteControllerTest extends ApiTestCase
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

        // Custom role on tenant A — mutable by tenant A admin.
        $customA = new Role('custom_a', 'Custom Role A', $tenantA);
        $em->persist($customA);

        // Custom role on tenant B — tenant A admin must see this as 404.
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
    }

    // --- Escalation: non-admin is denied write (403) ----------------------

    #[Test]
    public function nonAdminCannotCreateRole(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('POST', '/api/roles', [
            'json' => ['name' => 'Escalated Role', 'permission_codes' => []],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    #[Test]
    public function nonAdminCannotUpdateRole(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('PATCH', '/api/roles/'.$this->customAId, [
            'json' => ['permission_codes' => ['user.admin']],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    #[Test]
    public function nonAdminCannotDeleteRole(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('DELETE', '/api/roles/'.$this->customAId);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    // --- Cross-tenant: another tenant's role is invisible (404) -----------

    #[Test]
    public function adminCannotUpdateForeignTenantRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PATCH', '/api/roles/'.$this->customBId, [
            'json' => ['permission_codes' => ['user.admin']],
        ]);

        // 404, NOT 403 — do not reveal that the role exists on another tenant.
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function adminCannotDeleteForeignTenantRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('DELETE', '/api/roles/'.$this->customBId);

        self::assertResponseStatusCodeSame(404);

        // The foreign role must survive the cross-tenant delete attempt.
        $this->em()->clear();
        $survivor = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findById(Uuid::fromString($this->customBId));
        self::assertNotNull($survivor, 'Cross-tenant DELETE must not remove the foreign role.');
    }

    #[Test]
    public function unknownRoleReturns404ForAdmin(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PATCH', '/api/roles/'.Uuid::v7()->toRfc4122(), [
            'json' => ['permission_codes' => []],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // --- Happy path: admin can write (proves the 403s are authz) ----------

    #[Test]
    public function adminCanCreateCustomRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/roles', [
            'json' => [
                'name' => 'QA Reviewer',
                'code' => 'qa_reviewer',
                'permission_codes' => [],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);

        $this->em()->clear();
        $created = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('qa_reviewer', $this->tenantA());
        self::assertNotNull($created, 'Admin POST must persist the custom role on the caller tenant.');
    }

    #[Test]
    public function adminCanUpdateOwnTenantCustomRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('PATCH', '/api/roles/'.$this->customAId, [
            'json' => ['permission_codes' => ['user.admin']],
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function adminCanDeleteUnassignedOwnTenantCustomRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('DELETE', '/api/roles/'.$this->customAId);

        self::assertResponseStatusCodeSame(204);

        $this->em()->clear();
        $deleted = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findById(Uuid::fromString($this->customAId));
        self::assertNull($deleted, 'Admin DELETE must remove the own-tenant custom role.');
    }

    #[Test]
    public function adminCannotDeleteBuiltInRole(): void
    {
        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $builtIn = $roles->findByCode(RbacMatrix::ROLE_VIEWER, $this->tenantA());
        \assert(null !== $builtIn);

        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('DELETE', '/api/roles/'.$builtIn->getId()->toRfc4122());

        // Built-in roles are protected even from a full admin.
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * #2837 — protection used to key on `tenant_id IS NULL`, which held
     * only while tenant roles were also seeded globally. With the
     * duplicates gone, every PRD template role would have been deletable
     * (and renameable) from the panel the moment nobody held it —
     * `tenant_owner` included.
     */
    #[Test]
    public function prdTemplateRolesAreProtectedFromDeletionAndRename(): void
    {
        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $client = $this->clientFor(self::ADMIN_A_EMAIL);

        foreach (['tenant_owner', 'catalog_manager', 'approver'] as $code) {
            $role = $roles->findByCode($code, $this->tenantA());
            \assert(null !== $role);
            $id = $role->getId()->toRfc4122();

            $client->request('DELETE', '/api/roles/'.$id);
            self::assertResponseStatusCodeSame(403, $code.' must not be deletable');

            $client->request('PATCH', '/api/roles/'.$id, [
                'json' => ['name' => 'Renamed '.$code],
            ]);
            self::assertResponseStatusCodeSame(400, $code.' must not be renameable');
        }
    }

    /** A genuinely user-made role stays fully editable. */
    #[Test]
    public function customRoleRemainsDeletable(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('DELETE', '/api/roles/'.$this->customAId);

        self::assertResponseStatusCodeSame(204);
    }

    private function tenantA(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_A_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
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
