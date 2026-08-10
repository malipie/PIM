<?php

declare(strict_types=1);

namespace App\Tests\Api\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Identity\Application\ByokKeyManager;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P6-03 (#2346) — the bulk content HTTP surface: cost-preview
 * (deterministic estimate, gated read) and bulk-generate (202 + one run
 * + one batch, gated create), plus the §8.5 day-cap refusal (429) when
 * the tenant is already at the spend cap.
 */
final class AgentContentBulkApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string VIEWER_EMAIL = 'viewer@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        self::getContainer()->get(PrdPermissionSeeder::class)->seed();
        $em->flush();

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $this->createUser($tenant, self::ADMIN_EMAIL, 'tenant_owner');
        $this->createUser($tenant, self::VIEWER_EMAIL, 'viewer');
        self::getContainer()->get(ByokKeyManager::class)->setKey($tenant, 'sk-ant-api03-canned-test-key');
    }

    #[Test]
    public function costPreviewRejectsAnonymousAndTheModulelessViewer(): void
    {
        $anon = static::createClient()->request('POST', '/api/agent/content/cost-preview', ['json' => ['product_count' => 5]]);
        self::assertSame(401, $anon->getStatusCode());

        $viewer = $this->authenticatedClient(self::VIEWER_EMAIL)
            ->request('POST', '/api/agent/content/cost-preview', ['json' => ['product_count' => 5]]);
        self::assertSame(403, $viewer->getStatusCode());
    }

    #[Test]
    public function costPreviewReturnsADeterministicScalingEstimate(): void
    {
        $client = $this->authenticatedClient();

        $response = $client->request('POST', '/api/agent/content/cost-preview', ['json' => [
            'product_count' => 10,
            'mode' => 'descriptions',
        ]]);
        self::assertSame(200, $response->getStatusCode());

        $data = $response->toArray();
        self::assertSame(10, $data['product_count']);
        self::assertIsInt($data['est_input_tokens']);
        self::assertIsInt($data['est_output_tokens']);
        self::assertIsInt($data['input_tokens_per_product']);
        self::assertGreaterThan(0, $data['est_input_tokens']);
        self::assertGreaterThan(0, $data['est_output_tokens']);
        self::assertArrayHasKey('est_cost_usd', $data);
        self::assertSame($data['input_tokens_per_product'] * 10, $data['est_input_tokens']);
    }

    #[Test]
    public function bulkGenerateAcceptsAndReturnsOneRunAndOneBatch(): void
    {
        $client = $this->authenticatedClient();

        $response = $client->request('POST', '/api/agent/content/bulk-generate', ['json' => [
            'object_type_code' => 'product',
            'mode' => 'descriptions',
            'selected_ids' => [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()],
        ]]);

        self::assertSame(202, $response->getStatusCode());
        $data = $response->toArray();
        self::assertArrayHasKey('run_id', $data);
        self::assertArrayHasKey('pending_change_batch_id', $data);
        self::assertSame(2, $data['product_count']);
        self::assertIsArray($data['estimate']);
        self::assertSame(2, $data['estimate']['product_count']);
    }

    #[Test]
    public function bulkGenerateRefusesWith400WhenNothingSelected(): void
    {
        $response = $this->authenticatedClient()->request('POST', '/api/agent/content/bulk-generate', ['json' => [
            'object_type_code' => 'product',
            'mode' => 'descriptions',
            'selected_ids' => [],
        ]]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function bulkGenerateRefusesWith429WhenTheDayCapIsExhausted(): void
    {
        // Seed a run today that already spent the whole $20 day cap.
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $spent = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'earlier run');
        $spent->addUsage(0, 0, '20.000000');
        $em->persist($spent);
        $em->flush();

        $response = $this->authenticatedClient()->request('POST', '/api/agent/content/bulk-generate', ['json' => [
            'object_type_code' => 'product',
            'mode' => 'descriptions',
            'selected_ids' => [Uuid::v7()->toRfc4122()],
        ]]);

        self::assertSame(429, $response->getStatusCode());
    }

    private function createUser(Tenant $tenant, string $email, string $roleCode): void
    {
        $em = $this->em();
        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($role);
        $em->persist($user);
        $em->flush();
    }

    private function authenticatedClient(string $email = self::ADMIN_EMAIL): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['Authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
