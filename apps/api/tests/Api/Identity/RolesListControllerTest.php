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
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * RBAC-P5-005 (#695) — Settings → Roles list endpoint coverage.
 *
 * Invariants:
 *  - lists every seeded global role plus the caller tenant's custom
 *    roles (custom from a different tenant must NOT appear);
 *  - `user_count` reflects only users from the caller tenant;
 *  - `type` discriminates system (global) vs custom;
 *  - non-admin (Catalog Manager) → 403, same `user.admin` gate as
 *    {@see UsersListController}.
 */
final class RolesListControllerTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_A_CODE = 'demo';
    private const string TENANT_B_CODE = 'other';

    private const string ADMIN_A_EMAIL = 'admin@demo.localhost';
    private const string CATALOG_A_EMAIL = 'catalog@demo.localhost';

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

        // Custom role on tenant A — must appear in the listing alongside
        // the 4 system templates.
        $customA = new Role('custom_a', 'Custom Role A', $tenantA);
        $em->persist($customA);

        // Custom role on tenant B — must NOT appear when authenticating
        // as tenant A admin (cross-tenant isolation).
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
    }

    #[Test]
    public function returnsSystemRolesPlusTenantCustom(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/roles');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse($client);

        // #2837 — the tenant carries the 9 PRD role templates instead of a
        // mix of global and per-tenant copies, plus tenant A's own custom_a.
        // custom_b lives on tenant B and must NOT appear. Asserting on the
        // codes below rather than pinning a count keeps this from breaking
        // every time the PRD template list grows.
        $codes = $this->extractField($body, 'code');
        self::assertContains(RbacMatrix::ROLE_CATALOG_MANAGER, $codes);
        self::assertContains(RbacMatrix::ROLE_INTEGRATION_MANAGER, $codes);
        self::assertContains(RbacMatrix::ROLE_VIEWER, $codes);
        self::assertContains('custom_a', $codes);
        self::assertNotContains('custom_b', $codes);
        // AUD-003 (#1575): the platform-level operator role is never offered
        // as an assignable tenant role.
        self::assertNotContains(RbacMatrix::ROLE_PLATFORM_OPERATOR, $codes);
        // #2881: neither is super_admin. It is a GLOBAL row shared by every
        // tenant, so listing it invited an edit of an object that does not
        // belong to this tenant — and the tenant-scoped assignment lookup
        // never resolved it anyway, so it was never selectable. This
        // assertion used to be assertContains; the change is deliberate.
        self::assertNotContains(RbacMatrix::ROLE_SUPER_ADMIN, $codes);
    }

    #[Test]
    public function classifiesRoleTypeAsSystemOrCustom(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/roles');

        $body = $this->decodeResponse($client);
        $members = $body['member'] ?? [];
        \assert(\is_array($members));

        foreach ($members as $row) {
            \assert(\is_array($row));
            $code = $row['code'] ?? null;
            $type = $row['type'] ?? null;
            $isBuiltIn = $row['is_built_in'] ?? null;
            if ('custom_a' === $code) {
                self::assertSame('custom', $type);
                self::assertFalse($isBuiltIn);
            } else {
                self::assertSame('system', $type);
                self::assertTrue($isBuiltIn);
            }
        }
    }

    #[Test]
    public function reportsUserCountPerRole(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('GET', '/api/roles');

        $body = $this->decodeResponse($client);
        $counts = $this->extractCounts($body);

        // The fixture wires one admin + one catalog_manager on tenant A.
        // #2881 — super_admin is no longer listed, so it has no count row.
        self::assertArrayNotHasKey(RbacMatrix::ROLE_SUPER_ADMIN, $counts);
        self::assertSame(1, $counts[RbacMatrix::ROLE_CATALOG_MANAGER] ?? null);
        self::assertSame(0, $counts[RbacMatrix::ROLE_VIEWER] ?? null);
        self::assertSame(0, $counts['custom_a'] ?? null);
    }

    #[Test]
    public function nonAdminUserReceives403(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('GET', '/api/roles');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
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
    private function extractField(array $body, string $field): array
    {
        $members = $body['member'] ?? [];
        \assert(\is_array($members));
        $out = [];
        foreach ($members as $row) {
            \assert(\is_array($row));
            $value = $row[$field] ?? null;
            \assert(\is_string($value));
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, int>
     */
    private function extractCounts(array $body): array
    {
        $members = $body['member'] ?? [];
        \assert(\is_array($members));
        $out = [];
        foreach ($members as $row) {
            \assert(\is_array($row));
            $code = $row['code'] ?? null;
            $count = $row['user_count'] ?? null;
            \assert(\is_string($code) && \is_int($count));
            $out[$code] = $count;
        }

        return $out;
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
