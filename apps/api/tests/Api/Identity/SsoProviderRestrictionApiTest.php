<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
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
 * #2728 — an SSO provider without a directory restriction accepts every
 * account its IdP will authenticate, and SsoUserResolver auto-provisions each
 * one as `viewer` (read access to the tenant's whole catalogue). With an
 * "External" Google Cloud app and no `hosted_domain`, that means any private
 * Gmail address.
 *
 * The config is refused at the edge so the misconfiguration cannot be saved in
 * the first place; the providers additionally fail closed at login time, which
 * covers rows created before this rule.
 */
final class SsoProviderRestrictionApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string ADMIN_EMAIL = 'admin@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant('demo', 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = $roles->findByCode('tenant_owner', $tenant);
        \assert(null !== $tenantOwner);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::ADMIN_EMAIL, '');
        $admin = new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($stub, 'changeme'));
        $admin->addRole($superAdmin);
        $admin->addRole($tenantOwner);
        $em->persist($admin);
        $em->flush();
    }

    #[Test]
    public function googleProviderWithoutHostedDomainIsRejected(): void
    {
        $response = $this->post([
            'kind' => 'google_workspace',
            'name' => 'Google Workspace',
            'config' => ['client_id' => 'x.apps.googleusercontent.com', 'client_secret' => 'shh'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('hosted_domain', $response->getContent(false));
    }

    #[Test]
    public function googleProviderWithHostedDomainIsAccepted(): void
    {
        $this->post([
            'kind' => 'google_workspace',
            'name' => 'Google Workspace',
            'config' => [
                'client_id' => 'x.apps.googleusercontent.com',
                'client_secret' => 'shh',
                'hosted_domain' => 'demo.localhost',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    #[Test]
    public function microsoftProviderWithCommonDirectoryIsRejected(): void
    {
        // `common` is the multi-tenant endpoint — it authenticates any
        // Microsoft account, including personal ones.
        $response = $this->post([
            'kind' => 'microsoft_365',
            'name' => 'Microsoft 365',
            'config' => ['client_id' => 'app-id', 'client_secret' => 'shh', 'tenant_id' => 'common'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('tenant_id', $response->getContent(false));
    }

    #[Test]
    public function patchCannotRemoveTheRestrictionFromAnExistingProvider(): void
    {
        $created = $this->post([
            'kind' => 'google_workspace',
            'name' => 'Google Workspace',
            'config' => [
                'client_id' => 'x.apps.googleusercontent.com',
                'client_secret' => 'shh',
                'hosted_domain' => 'demo.localhost',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $created->toArray()['id'];
        \assert(\is_string($id));

        static::createClient()->request('PATCH', '/api/sso/providers/'.$id, [
            'headers' => ['authorization' => 'Bearer '.$this->adminJwt(), 'content-type' => 'application/json'],
            'body' => json_encode([
                'config' => ['client_id' => 'x.apps.googleusercontent.com', 'client_secret' => '****'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return static::createClient()->request('POST', '/api/sso/providers', [
            'headers' => ['authorization' => 'Bearer '.$this->adminJwt(), 'content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    private function adminJwt(): string
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::ADMIN_EMAIL);
        \assert(null !== $user);

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
