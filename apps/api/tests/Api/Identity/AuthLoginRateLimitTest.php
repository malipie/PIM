<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Identity\Application\RbacSeeder;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * Coverage for #48 (0.4.8) — auth_login rate limiter.
 *
 * Five FAILED attempts inside one window exhaust the budget; the next
 * request from that IP gets a 429 with `Retry-After`.
 *
 * #2881 — this file used to assert the opposite of its second test:
 * that a successful login also consumed the budget. That was the
 * behaviour, and the behaviour was wrong. Five correct passwords in a
 * row locked the caller out for fifteen minutes, which is what an
 * administrator moving between accounts does, and what verifying a
 * deployment does. Brute force is made of failures; the limiter now
 * counts those.
 *
 * The half of the old rationale worth keeping is pinned below: a
 * success does not RESET the budget either, so guessing a password
 * mid-run does not re-arm the limiter for the attacker.
 */
final class AuthLoginRateLimitTest extends ApiTestCase
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

        // Rate limiter state persists across tests because the underlying
        // cache pool is filesystem-backed in dev/test. Reset the limiter
        // for the BrowserKit-default IP (`127.0.0.1`) so each test starts
        // with a fresh budget.
        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        $superAdmin = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::ADMIN_EMAIL, '');
        $admin = new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($stub, self::ADMIN_PASSWORD));
        $admin->addRole($superAdmin);
        $em->persist($admin);
        $em->flush();
    }

    #[Test]
    public function sixthLoginAttemptInWindowReturns429WithRetryAfter(): void
    {
        $client = static::createClient();

        // Five wrong-password attempts — each returns 401 (no token).
        for ($i = 1; $i <= 5; ++$i) {
            $client->request('POST', '/api/auth/login', [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(
                    ['email' => self::ADMIN_EMAIL, 'password' => 'wrong-'.$i],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
            self::assertResponseStatusCodeSame(401, 'Attempt #'.$i.' must still hit Lexik (not the limiter).');
        }

        // Sixth attempt — the limiter rejects it before Lexik runs.
        $response = $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => 'wrong-6'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        self::assertResponseStatusCodeSame(429);
        // The 429 from the limiter MUST advertise when the budget refills.
        self::assertNotNull(
            $response->getHeaders(throw: false)['retry-after'][0] ?? null,
            'The throttled response must carry a Retry-After header.',
        );
    }

    /**
     * The operator's case, in the shape production logged it: several
     * correct logins in a row from one address, which used to end in a
     * 429 and now must not.
     */
    #[Test]
    public function successfulLoginsDoNotConsumeTheBudget(): void
    {
        $client = static::createClient();

        for ($i = 1; $i <= 8; ++$i) {
            $client->request('POST', '/api/auth/login', [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(
                    ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
            self::assertResponseStatusCodeSame(200, 'Successful login #'.$i.' must not be throttled.');
        }
    }

    /**
     * The half of the original rationale that survives: a correct
     * password does not forgive the failures already recorded. Without
     * this, an attacker who lands one guess mid-run re-arms the limiter
     * and keeps going.
     */
    #[Test]
    public function aSuccessDoesNotForgiveEarlierFailures(): void
    {
        $client = static::createClient();

        for ($i = 1; $i <= 4; ++$i) {
            $client->request('POST', '/api/auth/login', [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(
                    ['email' => self::ADMIN_EMAIL, 'password' => 'wrong-'.$i],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        self::assertResponseStatusCodeSame(200, 'the budget still has one failure left');

        // Fifth failure exhausts it; the one after that is refused
        // before Lexik sees it, correct password or not.
        $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => 'wrong-5'],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        self::assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        self::assertResponseStatusCodeSame(429);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
