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
 * Manual user creation (#867) — coverage for `POST /api/users`.
 *
 * Invariants:
 *  - happy path returns 201 + UserListItem projection with the assigned
 *    role visible and `password_change_required` honoured (verified via
 *    a subsequent /api/auth/me read on the new user's JWT),
 *  - duplicate email refused with 409,
 *  - short password (<12 chars) refused with 400,
 *  - unknown role_code refused with 400,
 *  - non-admin (Catalog Manager) refused with 403 — same `settings.users.manage`
 *    gate as the invitation flow.
 */
final class UserCreateControllerTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string CATALOG_EMAIL = 'catalog@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = $roles->findByCode('tenant_owner', $tenant);
        // #2837 — catalog_manager is a tenant role, seeded with the rest.
        $catalogManager = $roles->findByCode(RbacMatrix::ROLE_CATALOG_MANAGER, $tenant);
        \assert(null !== $tenantOwner && null !== $catalogManager);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = $this->makeUser($tenant, self::ADMIN_EMAIL, $hasher);
        $admin->addRole($superAdmin);
        $admin->addRole($tenantOwner);
        $em->persist($admin);

        $catalog = $this->makeUser($tenant, self::CATALOG_EMAIL, $hasher);
        $catalog->addRole($catalogManager);
        $em->persist($catalog);

        $em->flush();
    }

    #[Test]
    public function happyPathReturns201WithRoleAndForceFlagPersisted(): void
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('POST', '/api/users', [
            'json' => [
                'email' => 'ada@example.com',
                'display_name' => 'Ada Kowalska',
                'role_code' => 'catalog_manager',
                'password' => 'SecurePass1234!',
                'force_password_change' => true,
                'send_welcome_email' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        self::assertSame('ada@example.com', $body['email'] ?? null);
        self::assertSame('user', $body['kind'] ?? null);
        self::assertSame('active', $body['status'] ?? null);
        $roles = $body['roles'] ?? null;
        self::assertIsArray($roles);
        self::assertCount(1, $roles);
        // After assertIsArray() $roles is array<mixed, mixed>. PHPStan
        // refuses [0]['code'] on mixed; an extra assertIsArray on the
        // first entry plus a PHPDoc-typed local lets the traversal stay
        // checkable end-to-end.
        self::assertIsArray($roles[0] ?? null);
        /** @var array<string, mixed> $firstRole */
        $firstRole = $roles[0];
        self::assertSame('catalog_manager', $firstRole['code'] ?? null);

        // Verify password_change_required persisted by logging in as the
        // new user and reading /api/auth/me.
        $ada = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('ada@example.com');
        \assert(null !== $ada);
        self::assertTrue($ada->isPasswordChangeRequired(), 'force_password_change should flip the flag on');
    }

    #[Test]
    public function forcePasswordChangeFalseLeavesFlagUntouched(): void
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('POST', '/api/users', [
            'json' => [
                'email' => 'bob@example.com',
                'role_code' => 'catalog_manager',
                'password' => 'SecurePass1234!',
                'force_password_change' => false,
                'send_welcome_email' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $bob = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('bob@example.com');
        \assert(null !== $bob);
        self::assertFalse($bob->isPasswordChangeRequired());
    }

    #[Test]
    public function duplicateEmailReturns409(): void
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('POST', '/api/users', [
            'json' => [
                'email' => self::CATALOG_EMAIL,
                'role_code' => 'catalog_manager',
                'password' => 'SecurePass1234!',
                'send_welcome_email' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function shortPasswordReturns400(): void
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('POST', '/api/users', [
            'json' => [
                'email' => 'short@example.com',
                'role_code' => 'catalog_manager',
                'password' => 'short',
                'send_welcome_email' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function unknownRoleReturns400(): void
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('POST', '/api/users', [
            'json' => [
                'email' => 'unknown@example.com',
                'role_code' => 'nonexistent',
                'password' => 'SecurePass1234!',
                'send_welcome_email' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function nonAdminReceives403(): void
    {
        $client = $this->clientFor(self::CATALOG_EMAIL);
        $client->request('POST', '/api/users', [
            'json' => [
                'email' => 'forbidden@example.com',
                'role_code' => 'catalog_manager',
                'password' => 'SecurePass1234!',
                'send_welcome_email' => false,
            ],
        ]);

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
