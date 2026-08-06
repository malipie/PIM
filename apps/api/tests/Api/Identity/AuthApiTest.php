<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Identity\Application\RbacSeeder;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * Functional contract for the Sprint-0 auth slice (#4 / 0.0.4):
 *  - POST /api/auth/login with valid credentials returns a JWT
 *  - Wrong credentials return 401 (no token leak)
 *  - Protected endpoints require a Bearer JWT
 *  - A valid JWT lets the request through
 */
final class AuthApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string ADMIN_PASSWORD = 'changeme';

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the auth_login rate limiter (#48 / 0.4.8) — its budget
        // is filesystem-backed via cache.app and would carry across the
        // many login attempts this suite exercises.
        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        $superAdmin = self::getContainer()->get(RoleRepositoryInterface::class)->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);

        $hasher = $this->passwordHasher();
        $stub = new User($tenant, self::ADMIN_EMAIL, '');
        $admin = new User(
            $tenant,
            self::ADMIN_EMAIL,
            $hasher->hashPassword($stub, self::ADMIN_PASSWORD),
        );
        $admin->addRole($superAdmin);
        $em->persist($admin);
        $em->flush();
    }

    #[Test]
    public function loginWithValidCredentialsReturnsJwt(): void
    {
        $response = static::createClient()->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayHasKey('token', $body);
        $token = $body['token'];
        self::assertIsString($token);
        // Three base64url segments separated by dots is the JWS compact serialisation.
        self::assertMatchesRegularExpression('#^[\w-]+\.[\w-]+\.[\w-]+$#', $token);
    }

    #[Test]
    public function loginWithWrongPasswordReturns401(): void
    {
        static::createClient()->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => 'wrong'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function protectedEndpointWithoutTokenReturns401(): void
    {
        static::createClient()->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function protectedEndpointWithValidTokenReturns200(): void
    {
        $token = $this->loginAndExtractToken();

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$token]]);
        $client->request('GET', '/api/auth/me');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function protectedEndpointWithMalformedTokenReturns401(): void
    {
        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer not.a.real.jwt']]);
        $client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function failedLoginReturnsRfc7807ProblemDetails(): void
    {
        $response = static::createClient()->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => 'wrong'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');

        $body = $response->toArray(throw: false);
        self::assertSame('about:blank', $body['type'] ?? null);
        self::assertSame(401, $body['status'] ?? null);
        self::assertArrayHasKey('title', $body);
        self::assertArrayHasKey('detail', $body);
    }

    #[Test]
    public function logoutWithValidTokenReturns204(): void
    {
        $token = $this->loginAndExtractToken();

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$token]]);
        $client->request('POST', '/api/auth/logout');

        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function logoutWithoutTokenReturns401(): void
    {
        static::createClient()->request('POST', '/api/auth/logout');

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function fixtureAdminPasswordIsHashedWithArgon2id(): void
    {
        $repository = self::getContainer()->get(UserRepositoryInterface::class);
        $admin = $repository->findByEmail(self::ADMIN_EMAIL);
        self::assertNotNull($admin);

        // Argon2id PHC string is identifiable by its `$argon2id$` prefix; the
        // fact that it survives a roundtrip through Doctrine is what matters,
        // because the security.yaml change is silently ignored if the hasher
        // bundle picks something else (no warning, password just keeps its
        // legacy bcrypt format).
        self::assertStringStartsWith('$argon2id$', $admin->getPassword());
    }

    private function loginAndExtractToken(?string $email = null, ?string $password = null): string
    {
        $response = static::createClient()->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => $email ?? self::ADMIN_EMAIL, 'password' => $password ?? self::ADMIN_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $token = $response->toArray()['token'] ?? null;
        \assert(\is_string($token));

        return $token;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
