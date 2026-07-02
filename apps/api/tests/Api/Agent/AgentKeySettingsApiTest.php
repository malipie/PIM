<?php

declare(strict_types=1);

namespace App\Tests\Api\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\Identity\PrdPermissionFixtures;
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

use const JSON_THROW_ON_ERROR;

/**
 * AGENT-P6-06 (#1979) — BYOK settings surface: status without a key,
 * set -> prefix visible + plaintext NEVER echoed, rotate replaces the
 * prefix, disable soft-offs the agent, and the malformed-key guard.
 */
final class AgentKeySettingsApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        $prdPermissions = new PrdPermissionFixtures();
        $prdPermissions->load($em);
        $em->flush();

        $superAdmin = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('tenant_owner', $tenant);
        \assert(null !== $tenantOwner);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::ADMIN_EMAIL, '', ['ROLE_USER']);
        $admin = new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $admin->addRole($superAdmin);
        $admin->addRole($tenantOwner);
        $em->persist($admin);
        $em->flush();
    }

    #[Test]
    public function fullKeyLifecycleNeverEchoesPlaintext(): void
    {
        $client = $this->authenticatedClient();

        $status = $client->request('GET', '/api/settings/agent-key');
        self::assertSame(200, $status->getStatusCode());
        $payload = $status->toArray(false);
        self::assertFalse($payload['configured']);
        self::assertFalse($payload['enabled']);

        $malformed = $client->request('PUT', '/api/settings/agent-key', [
            'json' => ['api_key' => 'not-an-anthropic-key'],
        ]);
        self::assertSame(400, $malformed->getStatusCode());

        $set = $client->request('PUT', '/api/settings/agent-key', [
            'json' => ['api_key' => 'sk-ant-api03-first-secret-key-123456'],
        ]);
        self::assertSame(200, $set->getStatusCode());
        $setPayload = $set->toArray(false);
        self::assertTrue($setPayload['enabled']);
        self::assertIsString($setPayload['key_prefix']);
        self::assertStringNotContainsString('first-secret-key', json_encode($setPayload, JSON_THROW_ON_ERROR), 'plaintext must never come back');

        // Rotate: a new key replaces the old one.
        $rotate = $client->request('PUT', '/api/settings/agent-key', [
            'json' => ['api_key' => 'sk-ant-api03-rotated-secret-999999'],
        ]);
        self::assertSame(200, $rotate->getStatusCode());

        $status = $client->request('GET', '/api/settings/agent-key')->toArray(false);
        self::assertTrue($status['configured']);
        self::assertTrue($status['enabled']);
        self::assertStringNotContainsString('secret', json_encode($status, JSON_THROW_ON_ERROR));

        // Disable: agent soft-off; starting a run refuses (403).
        $disable = $client->request('DELETE', '/api/settings/agent-key');
        self::assertSame(200, $disable->getStatusCode());
        $status = $client->request('GET', '/api/settings/agent-key')->toArray(false);
        self::assertFalse($status['enabled']);

        $refused = $client->request('POST', '/api/agent/runs', [
            'json' => ['intent' => 'anything'],
        ]);
        self::assertSame(403, $refused->getStatusCode(), 'disable must soft-off the agent');
    }

    #[Test]
    public function anonymousIsRejected(): void
    {
        $response = static::createClient()->request('GET', '/api/settings/agent-key');
        self::assertSame(401, $response->getStatusCode());
    }

    private function authenticatedClient(): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::ADMIN_EMAIL);
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
