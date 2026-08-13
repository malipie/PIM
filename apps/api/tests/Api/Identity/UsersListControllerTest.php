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
 * RBAC-P5-001 (#691) — coverage for the Settings → Users list endpoint.
 *
 * Critical invariants (PRD §3.2 macierz + §3.4 tenant isolation):
 *  - cross-tenant boundary holds (tenant B users invisible to tenant A)
 *  - `user.admin` permission required (Catalog Manager → 403)
 *  - filters (status / role / search) narrow the result set correctly
 *  - response shape is Hydra-compatible (`member`, `totalItems`, `meta`)
 *  - sensitive fields (password / totp_secret) never appear in the payload
 */
final class UsersListControllerTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_A_CODE = 'demo';
    private const string TENANT_B_CODE = 'other';

    private const string ADMIN_A_EMAIL = 'admin@demo.localhost';
    private const string CATALOG_A_EMAIL = 'catalog@demo.localhost';
    private const string VIEWER_A_EMAIL = 'viewer@demo.localhost';
    private const string ADMIN_B_EMAIL = 'admin@other.localhost';

    private string $catalogManagerRoleId = '';

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
        $viewer = $roles->findByCode(RbacMatrix::ROLE_VIEWER, $tenantA);
        $catalogManager = $roles->findByCode(RbacMatrix::ROLE_CATALOG_MANAGER, $tenantA);
        \assert(null !== $viewer && null !== $catalogManager);
        $this->catalogManagerRoleId = $catalogManager->getId()->toRfc4122();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $adminA = $this->makeUser($tenantA, self::ADMIN_A_EMAIL, $hasher);
        $adminA->addRole($superAdmin);
        $em->persist($adminA);

        $catalogManagerA = $this->makeUser($tenantA, self::CATALOG_A_EMAIL, $hasher);
        $catalogManagerA->addRole($catalogManager);
        $em->persist($catalogManagerA);

        $viewerA = $this->makeUser($tenantA, self::VIEWER_A_EMAIL, $hasher);
        $viewerA->addRole($viewer);
        $viewerA->disable();
        $em->persist($viewerA);

        $adminB = $this->makeUser($tenantB, self::ADMIN_B_EMAIL, $hasher);
        $adminB->addRole($superAdmin);
        $em->persist($adminB);

        $em->flush();
    }

    #[Test]
    public function returnsOnlyUsersFromAuthenticatedTenant(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/users');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);

        self::assertArrayHasKey('member', $body);
        self::assertArrayHasKey('totalItems', $body);
        self::assertArrayHasKey('meta', $body);
        self::assertSame(3, $body['totalItems'] ?? null);

        $emails = $this->emailsOf($body);
        self::assertContains(self::ADMIN_A_EMAIL, $emails);
        self::assertContains(self::CATALOG_A_EMAIL, $emails);
        self::assertContains(self::VIEWER_A_EMAIL, $emails);
        self::assertNotContains(self::ADMIN_B_EMAIL, $emails);
    }

    #[Test]
    public function catalogManagerWithoutAdminPermissionReceives403(): void
    {
        // Catalog Manager has the read flag on user via "read on all resources"
        // but lacks `user.admin` which is super-admin-only in the seeded
        // matrix. Until #720 retrofit migrates onto `settings.users.manage`,
        // user.admin is the closest existing super-admin-only gate.
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('GET', '/api/users');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    #[Test]
    public function filtersByStatus(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/users?status=disabled');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);

        self::assertSame(1, $body['totalItems'] ?? null);
        $emails = $this->emailsOf($body);
        self::assertSame([self::VIEWER_A_EMAIL], $emails);
    }

    #[Test]
    public function filtersByRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/users?role[]='.$this->catalogManagerRoleId);

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);

        self::assertSame(1, $body['totalItems'] ?? null);
        $emails = $this->emailsOf($body);
        self::assertSame([self::CATALOG_A_EMAIL], $emails);
    }

    #[Test]
    public function searchesByEmailSubstringCaseInsensitive(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/users?search=CATALOG');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);

        self::assertSame(1, $body['totalItems'] ?? null);
        $emails = $this->emailsOf($body);
        self::assertSame([self::CATALOG_A_EMAIL], $emails);
    }

    #[Test]
    public function projectionDoesNotLeakSensitiveFields(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/users');

        $body = $this->decodeResponse($client);
        $members = $body['member'] ?? [];
        \assert(\is_array($members) && [] !== $members);
        $first = $members[0];
        \assert(\is_array($first));

        self::assertArrayNotHasKey('password', $first);
        self::assertArrayNotHasKey('totp_secret', $first);
        self::assertArrayNotHasKey('totpSecret', $first);
        self::assertArrayNotHasKey('totp_backup_codes', $first);
        self::assertArrayHasKey('mfa_enabled', $first);
        self::assertIsBool($first['mfa_enabled']);
    }

    #[Test]
    public function returnsMetaWithPaginationDetails(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/users?itemsPerPage=2&page=1');

        $body = $this->decodeResponse($client);
        $members = $body['member'] ?? [];
        \assert(\is_array($members));
        $meta = $body['meta'] ?? [];
        \assert(\is_array($meta));

        self::assertSame(3, $body['totalItems'] ?? null);
        self::assertCount(2, $members);
        self::assertSame(1, $meta['page'] ?? null);
        self::assertSame(2, $meta['per_page'] ?? null);
        self::assertSame(2, $meta['total_pages'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Client $client): array
    {
        $response = $client->getResponse();
        \assert(null !== $response);

        /** @var array<string, mixed> $payload */
        $payload = $response->toArray();

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function emailsOf(array $body): array
    {
        $members = $body['member'] ?? [];
        \assert(\is_array($members));
        $emails = [];
        foreach ($members as $row) {
            \assert(\is_array($row));
            $email = $row['email'] ?? null;
            \assert(\is_string($email));
            $emails[] = $email;
        }

        return $emails;
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
