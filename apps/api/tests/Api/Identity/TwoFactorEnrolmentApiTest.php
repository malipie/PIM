<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\TotpEnrolmentService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Crypto\SecretCipher;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * 2FA TOTP enrolment endpoints (#0.11.1).
 *
 * Covers the three endpoints exposed by `TwoFactorController`:
 *   - POST /api/auth/2fa/enrol   — provisions secret + recovery codes
 *   - POST /api/auth/2fa/verify  — confirms first authenticator code
 *   - POST /api/auth/2fa/disable — wipes the setup after re-verification
 *
 * Each test runs against a freshly seeded admin user inside its own
 * tenant; the JWT issued by `/api/auth/login` carries the principal
 * through to the controller.
 */
final class TwoFactorEnrolmentApiTest extends ApiTestCase
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
    public function enrolReturnsSecretProvisioningUriAndBackupCodes(): void
    {
        $client = static::createClient();
        $token = $this->login($client);

        $response = $client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        self::assertResponseIsSuccessful();
        $body = self::readEnrolment($response->toArray());

        self::assertNotSame('', $body['secret']);
        self::assertStringStartsWith('otpauth://totp/', $body['provisioning_uri']);
        self::assertCount(TotpEnrolmentService::BACKUP_CODE_COUNT, $body['backup_codes']);
        foreach ($body['backup_codes'] as $code) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $code);
        }

        // Persistence side: secret is stored ENCRYPTED (#2726 — a leaked
        // `users` dump must not yield mintable second factors), 2FA NOT yet
        // enabled.
        $admin = $this->reloadAdmin();
        $stored = $admin->getTotpSecret();
        self::assertIsString($stored);
        self::assertNotSame($body['secret'], $stored, 'the base32 secret must never sit in the column');
        self::assertStringNotContainsString($body['secret'], $stored);
        self::assertStringStartsWith('enc:v', $stored, 'the column holds a SecretCipher envelope');
        self::assertSame(
            $body['secret'],
            self::getContainer()->get(SecretCipher::class)->reveal($stored),
            'and it decrypts back to the secret the authenticator app scanned',
        );
        self::assertFalse($admin->isTotpEnabled());
        self::assertCount(TotpEnrolmentService::BACKUP_CODE_COUNT, $admin->getTotpBackupCodes());
    }

    #[Test]
    public function verifyAcceptsCorrectCodeAndEnablesTotp(): void
    {
        $client = static::createClient();
        $token = $this->login($client);

        $enrol = self::readEnrolment($client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ])->toArray());

        $code = TOTP::createFromSecret($enrol['secret'])->now();

        $response = $client->request('POST', '/api/auth/2fa/verify', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(['code' => $code], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertTrue($body['enabled']);
        self::assertNotNull($body['enabled_at']);

        self::assertTrue($this->reloadAdmin()->isTotpEnabled());
    }

    #[Test]
    public function verifyRejectsWrongCode(): void
    {
        $client = static::createClient();
        $token = $this->login($client);

        $client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        $client->request('POST', '/api/auth/2fa/verify', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(['code' => '000000'], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->reloadAdmin()->isTotpEnabled());
    }

    #[Test]
    public function disableWipesTheSetupWhenCodeMatches(): void
    {
        $client = static::createClient();
        $token = $this->login($client);

        $enrol = self::readEnrolment($client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ])->toArray());
        $totp = TOTP::createFromSecret($enrol['secret']);

        $client->request('POST', '/api/auth/2fa/verify', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(['code' => $totp->now()], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/auth/2fa/disable', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(['code' => $totp->now()], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        $admin = $this->reloadAdmin();
        self::assertFalse($admin->isTotpEnabled());
        self::assertNull($admin->getTotpSecret());
        self::assertSame([], $admin->getTotpBackupCodes());
    }

    #[Test]
    public function disableAcceptsBackupCodeAsAlternative(): void
    {
        $client = static::createClient();
        $token = $this->login($client);

        $enrol = self::readEnrolment($client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ])->toArray());

        $client->request('POST', '/api/auth/2fa/verify', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(
                ['code' => TOTP::createFromSecret($enrol['secret'])->now()],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        self::assertResponseIsSuccessful();

        $backupCode = $enrol['backup_codes'][0];

        $client->request('POST', '/api/auth/2fa/disable', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(['code' => $backupCode], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->reloadAdmin()->isTotpEnabled());
    }

    #[Test]
    public function enrolRefusesWhenAlreadyActive(): void
    {
        $client = static::createClient();
        $token = $this->login($client);

        $enrol = self::readEnrolment($client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ])->toArray());
        $client->request('POST', '/api/auth/2fa/verify', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'content-type' => 'application/json',
            ],
            'body' => json_encode(
                ['code' => TOTP::createFromSecret($enrol['secret'])->now()],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $client->request('POST', '/api/auth/2fa/enrol', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    /**
     * Narrow the loose `array<int|string, mixed>` returned by ApiTestCase
     * into the structured enrolment payload — keeps PHPStan max happy
     * while every test still reads `$body['secret']` etc. directly.
     *
     * @param array<int|string, mixed> $payload
     *
     * @return array{secret: non-empty-string, provisioning_uri: non-empty-string, backup_codes: list<string>}
     */
    private static function readEnrolment(array $payload): array
    {
        self::assertArrayHasKey('secret', $payload);
        self::assertArrayHasKey('provisioning_uri', $payload);
        self::assertArrayHasKey('backup_codes', $payload);

        $secret = $payload['secret'];
        $provisioningUri = $payload['provisioning_uri'];
        $backupCodes = $payload['backup_codes'];

        self::assertIsString($secret);
        self::assertNotSame('', $secret);
        self::assertIsString($provisioningUri);
        self::assertNotSame('', $provisioningUri);
        self::assertIsArray($backupCodes);
        self::assertIsList($backupCodes);
        $cleanCodes = [];
        foreach ($backupCodes as $code) {
            self::assertIsString($code);
            self::assertNotSame('', $code);
            $cleanCodes[] = $code;
        }

        // PHPStan cannot infer the conditional narrowing into a non-empty
        // shape from the asserts above — guarantee it explicitly.
        \assert('' !== $secret);
        \assert('' !== $provisioningUri);

        return [
            'secret' => $secret,
            'provisioning_uri' => $provisioningUri,
            'backup_codes' => $cleanCodes,
        ];
    }

    private function login(\ApiPlatform\Symfony\Bundle\Test\Client $client): string
    {
        $response = $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        self::assertResponseIsSuccessful();

        $token = $response->toArray()['token'];
        \assert(\is_string($token));

        return $token;
    }

    private function reloadAdmin(): User
    {
        $em = $this->em();
        $em->clear();
        $admin = self::getContainer()->get(UserRepositoryInterface::class)
            ->findByEmail(self::ADMIN_EMAIL);
        \assert($admin instanceof User);

        return $admin;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
