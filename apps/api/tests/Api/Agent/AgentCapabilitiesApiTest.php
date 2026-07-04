<?php

declare(strict_types=1);

namespace App\Tests\Api\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\Identity\PrdPermissionFixtures;
use App\Identity\Application\ByokKeyManager;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2246 — GET /api/agent/capabilities: quick-action chips derived from
 * the tool registry, RBAC-filtered per user, with graceful-degradation
 * reasons (missing BYOK key / no permission) reported as 200 + data
 * instead of a 403 problem document.
 */
final class AgentCapabilitiesApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string RESTRICTED_EMAIL = 'restricted@demo.localhost';
    private const string VIEWER_EMAIL = 'viewer@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        $prdPermissions = new PrdPermissionFixtures();
        $prdPermissions->load($em);
        $em->flush();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        $legacyCatalogManager = $roles->findGlobalByCode(RbacMatrix::ROLE_CATALOG_MANAGER);
        $legacyViewer = $roles->findGlobalByCode(RbacMatrix::ROLE_VIEWER);
        \assert(null !== $superAdmin && null !== $legacyCatalogManager && null !== $legacyViewer);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = $roles->findByCode('tenant_owner', $tenant);
        $marketing = $roles->findByCode('marketing', $tenant);
        \assert(null !== $tenantOwner && null !== $marketing);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $make = static function (string $email, Role ...$assigned) use ($em, $hasher, $tenant): void {
            $stub = new User($tenant, $email, '', ['ROLE_USER']);
            $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
            foreach ($assigned as $role) {
                $user->addRole($role);
            }
            $em->persist($user);
        };

        // Full surface: every tool's permission resolves.
        $make(self::ADMIN_EMAIL, $superAdmin, $tenantOwner);
        // Catalog-only: object.read/write via the legacy role,
        // agent.bulk_actions via PRD marketing — but NO
        // modeling.attributes.add_edit and NO integration.admin.
        $make(self::RESTRICTED_EMAIL, $legacyCatalogManager, $marketing);
        // Read-only legacy role without agent.bulk_actions.
        $make(self::VIEWER_EMAIL, $legacyViewer);
        $em->flush();

        self::getContainer()->get(TenantContext::class)->set($tenant);
    }

    #[Test]
    public function anonymousIsRejectedWith401(): void
    {
        $response = static::createClient()->request('GET', '/api/agent/capabilities');
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function missingByokKeyIsReportedAsDataNotA403(): void
    {
        $response = $this->authenticatedClient()->request('GET', '/api/agent/capabilities');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray(false);
        self::assertFalse($payload['enabled']);
        self::assertSame('missing_byok_key', $payload['reason']);
        self::assertSame([], $payload['actions']);
    }

    #[Test]
    public function adminSeesAllQuickActionsSortedByPriority(): void
    {
        $this->seedByokKey();
        $response = $this->authenticatedClient()->request('GET', '/api/agent/capabilities');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray(false);
        self::assertTrue($payload['enabled']);
        self::assertNull($payload['reason']);

        $actions = $payload['actions'];
        self::assertIsArray($actions);
        self::assertSame(
            ['create_update_attribute', 'assign_categories', 'generate_feed', 'completeness_report', 'bulk_edit_values'],
            array_column($actions, 'id'),
        );
        $first = $actions[0];
        self::assertIsArray($first);
        $label = $first['label'] ?? null;
        self::assertIsArray($label);
        self::assertSame('Dodaj atrybut', $label['pl'] ?? null);
        self::assertSame('Add attribute', $label['en'] ?? null);
        $prompt = $first['prompt'] ?? null;
        self::assertIsArray($prompt);
        self::assertIsString($prompt['pl'] ?? null);
    }

    #[Test]
    public function rbacFiltersChipsPerUser(): void
    {
        $this->seedByokKey();
        $response = $this->authenticatedClient(self::RESTRICTED_EMAIL)->request('GET', '/api/agent/capabilities');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray(false);
        self::assertTrue($payload['enabled']);

        // The schema chip (modeling.attributes.add_edit) and the feed
        // chip (integration.admin) must be filtered out.
        $actions = $payload['actions'];
        self::assertIsArray($actions);
        self::assertSame(
            ['assign_categories', 'completeness_report', 'bulk_edit_values'],
            array_column($actions, 'id'),
        );
    }

    #[Test]
    public function missingAgentPermissionAnswersNoPermission(): void
    {
        $this->seedByokKey();
        $response = $this->authenticatedClient(self::VIEWER_EMAIL)->request('GET', '/api/agent/capabilities');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray(false);
        self::assertFalse($payload['enabled']);
        self::assertSame('no_permission', $payload['reason']);
        self::assertSame([], $payload['actions']);
    }

    #[Test]
    public function noPermissionMasksTheByokKeyState(): void
    {
        // NO BYOK key seeded: a user without agent.bulk_actions must
        // still see no_permission, never the tenant's key state.
        $response = $this->authenticatedClient(self::VIEWER_EMAIL)->request('GET', '/api/agent/capabilities');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no_permission', $response->toArray(false)['reason']);
    }

    #[Test]
    public function autonomyOffYieldsEnabledWithoutActions(): void
    {
        $this->seedByokKey();

        // Autonomy 'off' empties the tool surface (a run refuses to plan)
        // but the endpoint stays enabled — consistent with Cmd+K.
        $em = $this->em();
        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        foreach ([$roles->findGlobalByCode(RbacMatrix::ROLE_CATALOG_MANAGER), $roles->findByCode('marketing', $tenant)] as $role) {
            \assert($role instanceof Role);
            $role->setAgentAutonomy('off');
        }
        $em->flush();

        $response = $this->authenticatedClient(self::RESTRICTED_EMAIL)->request('GET', '/api/agent/capabilities');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray(false);
        self::assertTrue($payload['enabled']);
        self::assertSame([], $payload['actions']);
    }

    private function seedByokKey(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(ByokKeyManager::class)->setKey($tenant, 'sk-ant-api03-canned-test-key');
    }

    private function authenticatedClient(string $email = self::ADMIN_EMAIL): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions([
            'headers' => ['Authorization' => 'Bearer '.$jwt],
        ]);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
