<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * RBAC-P5-004 (#694) — coverage for the deactivate / reactivate endpoints.
 *
 * Invariants:
 *  - cross-tenant boundary holds (target from another tenant → 404),
 *  - self-deactivation refused with 409,
 *  - last admin protection refused with 409 when only one tenant_owner
 *    / admin remains active,
 *  - happy paths return the refreshed user projection with the
 *    updated `status` field,
 *  - non-admin (Catalog Manager) gets 403 — same gate as
 *    `/api/users` list (PRD-pending retrofit).
 */
final class UserDeactivationControllerTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_A_CODE = 'demo';
    private const string TENANT_B_CODE = 'other';

    private const string ADMIN_A_EMAIL = 'admin@demo.localhost';
    private const string SECOND_ADMIN_A_EMAIL = 'second-admin@demo.localhost';
    private const string CATALOG_A_EMAIL = 'catalog@demo.localhost';
    private const string ADMIN_B_EMAIL = 'admin@other.localhost';

    private string $catalogUserId = '';
    private string $adminBUserId = '';
    private string $secondAdminId = '';
    private string $adminAUserId = '';

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        $catalogManager = $roles->findGlobalByCode(RbacMatrix::ROLE_CATALOG_MANAGER);
        \assert(null !== $superAdmin && null !== $catalogManager);

        $tenantA = new Tenant(self::TENANT_A_CODE, 'Demo Tenant');
        $tenantB = new Tenant(self::TENANT_B_CODE, 'Other Tenant');
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        // Seed PRD tenant role templates per tenant so `tenant_owner`
        // exists and the last-admin guard has something to count.
        $tenantRolesSeeder = self::getContainer()->get(SeedTenantPrdRolesService::class);
        $tenantRolesSeeder->seed($tenantA);
        $tenantRolesSeeder->seed($tenantB);

        $tenantOwnerA = $roles->findByCode('tenant_owner', $tenantA);
        $tenantOwnerB = $roles->findByCode('tenant_owner', $tenantB);
        \assert(null !== $tenantOwnerA && null !== $tenantOwnerB);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $adminA = $this->makeUser($tenantA, self::ADMIN_A_EMAIL, $hasher);
        $adminA->addRole($superAdmin);
        $adminA->addRole($tenantOwnerA);
        $em->persist($adminA);

        $secondAdmin = $this->makeUser($tenantA, self::SECOND_ADMIN_A_EMAIL, $hasher);
        $secondAdmin->addRole($tenantOwnerA);
        $em->persist($secondAdmin);

        $catalog = $this->makeUser($tenantA, self::CATALOG_A_EMAIL, $hasher);
        $catalog->addRole($catalogManager);
        $em->persist($catalog);

        $adminB = $this->makeUser($tenantB, self::ADMIN_B_EMAIL, $hasher);
        $adminB->addRole($superAdmin);
        $adminB->addRole($tenantOwnerB);
        $em->persist($adminB);

        $em->flush();

        $this->adminAUserId = $adminA->getId()->toRfc4122();
        $this->secondAdminId = $secondAdmin->getId()->toRfc4122();
        $this->catalogUserId = $catalog->getId()->toRfc4122();
        $this->adminBUserId = $adminB->getId()->toRfc4122();
    }

    #[Test]
    public function deactivatesSecondaryUserAndReturnsProjection(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->catalogUserId.'/deactivate');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);
        self::assertSame(self::CATALOG_A_EMAIL, $body['email'] ?? null);
        self::assertSame('disabled', $body['status'] ?? null);
    }

    #[Test]
    public function reactivatesUserBackToActive(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->catalogUserId.'/deactivate');
        $client->request('POST', '/api/users/'.$this->catalogUserId.'/reactivate');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);
        self::assertSame('active', $body['status'] ?? null);
    }

    #[Test]
    public function selfDeactivationReturns409(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->adminAUserId.'/deactivate');

        self::assertResponseStatusCodeSame(409);
        $body = $this->decodeResponse($client);
        self::assertSame('Self-deactivation forbidden', $body['title'] ?? null);
    }

    #[Test]
    public function lastAdminDeactivationReturns409(): void
    {
        // Caller (admin@demo) carries super_admin (→ `user.admin` so the
        // endpoint gate passes) AND tenant_owner (so they count as an
        // admin under the LastAdminGuard). We first deactivate the
        // secondary tenant_owner so admin@demo becomes the last
        // tenant-tier admin; then admin@demo tries to deactivate the
        // secondary user *again* (which is a no-op since they're
        // already disabled) — actually no, we need to target a still-
        // active admin role-bearer. Switch the scenario: try to remove
        // admin@demo themselves while they're the last admin. Self-
        // deactivation is forbidden so the 409 there hides last-admin.
        //
        // The clean isolation: a third user that holds tenant_owner.
        // admin@demo (super_admin + tenant_owner) → deactivate the
        // second_admin → only admin@demo holds tenant_owner now.
        // The guard refuses if admin@demo were to be deactivated by
        // someone else; for this test we use the same client to confirm
        // the secondary deactivation succeeded, then call deactivate on
        // admin@demo through a separate authenticated session. To skip
        // the self-protection block, we mint a JWT for the secondary
        // admin *before* disabling them — the JWT remains valid even
        // after `status=disabled` because the firewall has no on-the-
        // fly status check on each request (a separate hardening
        // ticket).
        $secondaryUser = self::getContainer()
            ->get(UserRepositoryInterface::class)
            ->findByEmail(self::SECOND_ADMIN_A_EMAIL);
        \assert(null !== $secondaryUser);
        $secondaryJwt = self::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($secondaryUser);

        // Promote the secondary admin to super_admin so their JWT
        // carries `user.admin` and clears the endpoint gate — then we
        // can isolate the LastAdminGuard branch.
        $em = $this->em();
        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);
        $secondary = self::getContainer()
            ->get(UserRepositoryInterface::class)
            ->findByEmail(self::SECOND_ADMIN_A_EMAIL);
        \assert(null !== $secondary);
        $secondary->addRole($superAdmin);
        $em->persist($secondary);
        $em->flush();
        $em->clear();

        // Drop the tenant_owner role from secondary so admin@demo is
        // the only remaining tenant_owner — this is the precondition
        // the guard checks.
        // ADR-0034 (#2832) — grants live in user_role_assignments now.
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM user_role_assignments WHERE user_id = :u AND role_id = (
                SELECT id FROM roles WHERE code = :code AND tenant_id = :t
            )',
            [
                'u' => $this->secondAdminId,
                'code' => 'tenant_owner',
                't' => self::getContainer()
                    ->get(UserRepositoryInterface::class)
                    ->findById(\Symfony\Component\Uid\Uuid::fromString($this->secondAdminId))
                    ?->getTenant()
                    ->getId()
                    ->toRfc4122(),
            ],
        );

        // Mint a *fresh* JWT for secondary (now super_admin + no
        // tenant_owner). Then attempt to deactivate admin@demo (last
        // tenant_owner) → expect 409 last_admin.
        $secondaryRefreshed = self::getContainer()
            ->get(UserRepositoryInterface::class)
            ->findByEmail(self::SECOND_ADMIN_A_EMAIL);
        \assert(null !== $secondaryRefreshed);
        $secondaryJwt = self::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($secondaryRefreshed);
        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$secondaryJwt]]);
        $client->request('POST', '/api/users/'.$this->adminAUserId.'/deactivate');

        self::assertResponseStatusCodeSame(409);
        $body = $this->decodeResponse($client);
        self::assertSame('last_admin', $body['code'] ?? null);
    }

    #[Test]
    public function crossTenantDeactivationReturns404(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->adminBUserId.'/deactivate');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function nonAdminReceives403(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->adminAUserId.'/deactivate');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Client $client): array
    {
        $response = $client->getResponse();
        \assert(null !== $response);

        /** @var array<string, mixed> $payload */
        $payload = $response->toArray(throw: false);

        return $payload;
    }

    private function makeUser(Tenant $tenant, string $email, UserPasswordHasherInterface $hasher): User
    {
        $stub = new User($tenant, $email, '');

        return new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'));
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
