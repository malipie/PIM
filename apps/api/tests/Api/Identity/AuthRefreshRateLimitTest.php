<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Identity\Application\RbacSeeder;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * Coverage for the refresh-token rate limiter (#97 / 0.11.2).
 *
 * 30 **failed** attempts per IP per hour — the listener returns 429 with
 * `Retry-After` on the next call.
 *
 * #2881 — successful rotations used to compete for the same budget, which
 * is a cap on using the app rather than a defence: every page load in the
 * admin refreshes, so an operator exhausted thirty in minutes and was
 * bounced to the login screen on every navigation afterwards. Reported
 * from production as "my role was taken away".
 *
 * The defence itself is unchanged — a stolen-cookie replay loop produces
 * failures, and those still count, which is what the first test pins.
 */
final class AuthRefreshRateLimitTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string ADMIN_PASSWORD = 'changeme';

    protected function setUp(): void
    {
        parent::setUp();
        // Reset both buckets for the BrowserKit default IP between tests —
        // the success path below logs in, which touches the login limiter.
        self::getContainer()->get('limiter.auth_refresh')->create('127.0.0.1')->reset();
        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        self::getContainer()->get(RbacSeeder::class)->seed();

        $tenant = new Tenant('demo', 'Demo Tenant');
        $em->persist($tenant);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::ADMIN_EMAIL, '');
        $em->persist(new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($stub, self::ADMIN_PASSWORD)));
        $em->flush();
    }

    /**
     * The attack shape: cookie-less (or replayed) calls fail, and failures
     * still run the bucket down.
     */
    #[Test]
    public function thirtyFirstFailedRefreshInWindowReturns429(): void
    {
        $client = static::createClient();

        // 30 cookie-less attempts — Lexik returns 401 for each (no token
        // payload to refresh), so each one spends a token and the 31st
        // call gets 429 from the listener before Lexik even runs.
        for ($i = 1; $i <= 30; ++$i) {
            $r = $client->request('POST', '/api/auth/refresh');
            self::assertNotSame(429, $r->getStatusCode(), 'Attempt #'.$i.' must not be rate-limited yet.');
        }

        $response = $client->request('POST', '/api/auth/refresh');
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($response->getHeaders(throw: false)['retry-after'][0] ?? null);
    }

    /**
     * The working shape: a browser holding a valid rotating cookie refreshes
     * far more often than thirty times an hour, and must never be throttled
     * for it.
     */
    #[Test]
    public function successfulRefreshesDoNotSpendTheBudget(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(
                ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        self::assertResponseIsSuccessful('the fixture admin must be able to log in');

        for ($i = 1; $i <= 35; ++$i) {
            $response = $client->request('POST', '/api/auth/refresh');
            self::assertSame(200, $response->getStatusCode(), 'Successful refresh #'.$i.' must not be throttled.');
        }
    }
}
