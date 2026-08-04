<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * #2725 — SSO provider credentials (`client_secret`, `private_key`,
 * `idp_certificate`, `sp_private_key`) used to sit in the `sso_providers.config`
 * JSONB in cleartext, so a database dump handed over the tenant's OAuth/SAML
 * credentials. They are now encrypted at rest.
 *
 * What this pins:
 *  - the secret never appears in the stored JSONB, while non-secret settings
 *    (client_id, hosted_domain) stay readable there,
 *  - the read projection still masks secrets with `****` (unchanged contract),
 *  - the masked-placeholder round-trip still works: PATCHing an unrelated field
 *    with `client_secret: '****'` must not clobber the stored credential.
 */
final class SsoProviderSecretsApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string CLIENT_SECRET = 'GOCSPX-super-secret-value-2725';

    protected function setUp(): void
    {
        parent::setUp();

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
    public function clientSecretIsEncryptedAtRestButStillUsableAndMasked(): void
    {
        $client = static::createClient();
        $jwt = $this->adminJwt();

        $created = $client->request('POST', '/api/sso/providers', [
            'headers' => ['authorization' => 'Bearer '.$jwt, 'content-type' => 'application/json'],
            'body' => json_encode([
                'kind' => 'google_workspace',
                'name' => 'Google Workspace',
                'config' => [
                    'client_id' => 'client-id-2725.apps.googleusercontent.com',
                    'client_secret' => self::CLIENT_SECRET,
                    'hosted_domain' => 'demo.localhost',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $providerId = $created->toArray()['id'];
        \assert(\is_string($providerId));

        // The stored JSONB must not contain the credential in any form.
        $storedConfig = $this->em()->getConnection()->fetchOne(
            'SELECT config::text FROM sso_providers WHERE id = :id',
            ['id' => $providerId],
        );
        self::assertIsString($storedConfig);
        self::assertStringNotContainsString(
            self::CLIENT_SECRET,
            $storedConfig,
            'the client secret must never be readable in the database',
        );
        self::assertStringContainsString('enc:v', $storedConfig, 'the secret leaf carries the cipher envelope');
        // Non-secret settings stay queryable / greppable.
        self::assertStringContainsString('demo.localhost', $storedConfig);
        self::assertStringContainsString('client-id-2725', $storedConfig);

        // The read projection still masks (unchanged contract). Only a list
        // endpoint exists for providers — the detail route is PATCH/DELETE.
        $read = $client->request('GET', '/api/sso/providers', [
            'headers' => ['authorization' => 'Bearer '.$jwt],
        ]);
        self::assertResponseStatusCodeSame(200);
        $rows = $read->toArray();
        $rows = \is_array($rows['member'] ?? null) ? $rows['member'] : $rows;
        self::assertIsArray($rows);
        $row = null;
        foreach ($rows as $candidate) {
            if (\is_array($candidate) && ($candidate['id'] ?? null) === $providerId) {
                $row = $candidate;
            }
        }
        self::assertIsArray($row, 'the created provider is listed');
        $config = $row['config'];
        self::assertIsArray($config);
        self::assertSame('****', $config['client_secret']);
        self::assertSame('demo.localhost', $config['hosted_domain']);

        // Masked round-trip: editing another field must not clobber the secret.
        $client->request('PATCH', '/api/sso/providers/'.$providerId, [
            'headers' => ['authorization' => 'Bearer '.$jwt, 'content-type' => 'application/json'],
            'body' => json_encode([
                'config' => [
                    'client_id' => 'client-id-2725.apps.googleusercontent.com',
                    'client_secret' => '****',
                    'hosted_domain' => 'demo.example',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);

        $afterPatch = $this->em()->getConnection()->fetchOne(
            'SELECT config::text FROM sso_providers WHERE id = :id',
            ['id' => $providerId],
        );
        self::assertIsString($afterPatch);
        self::assertStringNotContainsString(self::CLIENT_SECRET, $afterPatch);
        self::assertStringNotContainsString('"client_secret":"****"', $afterPatch, 'the mask must never be stored as the secret');
        self::assertStringContainsString('demo.example', $afterPatch, 'the edited field landed');
    }

    private function adminJwt(): string
    {
        $user = self::getContainer()->get(\App\Identity\Domain\Repository\UserRepositoryInterface::class)
            ->findByEmail(self::ADMIN_EMAIL);
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
