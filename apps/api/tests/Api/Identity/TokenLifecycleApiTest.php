<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\MagicLinkTokenHasher;
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
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * AUD-024 / W1-12 / J-03 — account-recovery & onboarding token lifecycle.
 *
 * Re-runs the lesson from RBAC Phase 2 re-audit (#657 invitation / #658
 * password-reset were shipped without an end-to-end test). Both flows put
 * the raw token on a PUBLIC_ACCESS endpoint, so a reusable / non-expiring /
 * leaked token is an account-takeover surface. This test pins:
 *
 *  Password reset ({@see \App\Identity\Presentation\Controller\PasswordResetController}):
 *   - request → confirm round-trip lets the user log in with the new password;
 *   - the token is SINGLE-USE — a second confirm with the same token is
 *     rejected (400);
 *   - an EXPIRED token is rejected (400);
 *   - confirm does NOT leak any token back in the response body.
 *
 *  Invitation / magic-link ({@see \App\Identity\Presentation\Controller\InvitationController}):
 *   - create → accept onboards the user (201) and they can then log in;
 *   - the token is SINGLE-USE — a second accept is rejected (400);
 *   - an EXPIRED invitation is rejected on accept (400) and reports
 *     `expired` (410) on verify;
 *   - accept does NOT leak any token back in the response body.
 *
 *  Note: there is no standalone magic-link login endpoint — the invitation
 *  accept flow IS the magic link (per InvitationController docblock).
 *
 *  The prod-omission of `token_dev_only` (AUD-007 / W0-5) is already pinned
 *  by {@see \App\Tests\Unit\Identity\Presentation\Controller\DevTokenExposureTest};
 *  here APP_ENV=test, so the dev token is present and used to drive consume.
 *
 * Status codes only (RFC 7807 detail differs debug/non-debug).
 */
final class TokenLifecycleApiTest extends ApiTestCase
{
    use Factories;
    use MailerAssertionsTrait;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string ADMIN_PASSWORD = 'changeme';
    private const string CATALOG_EMAIL = 'catalog@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();
        // W2-12 (AUD-030) added per-IP limiters on password-reset/request and
        // invitation accept. This class drives both flows many times from the
        // BrowserKit default IP, so the shared buckets would otherwise spill
        // over into 429s. Reset them per test, mirroring auth_login above.
        self::getContainer()->get('limiter.invitation_accept')->create('127.0.0.1')->reset();
        self::getContainer()->get('limiter.password_reset_ip')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        $catalogManager = $roles->findGlobalByCode(RbacMatrix::ROLE_CATALOG_MANAGER);
        \assert(null !== $superAdmin && null !== $catalogManager);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        // Per-tenant roles (viewer, tenant_owner, ...) — InvitationService
        // resolves role_code per tenant, so a global-only seed would 400.
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = $roles->findByCode('tenant_owner', $tenant);
        \assert(null !== $tenantOwner);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::ADMIN_EMAIL, '');
        $admin = new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($stub, self::ADMIN_PASSWORD));
        $admin->addRole($superAdmin);
        $admin->addRole($tenantOwner);
        $em->persist($admin);

        // Non-admin (Catalog Manager) for the invitation-create escalation guard.
        $catalogStub = new User($tenant, self::CATALOG_EMAIL, '');
        $catalog = new User($tenant, self::CATALOG_EMAIL, $hasher->hashPassword($catalogStub, 'changeme'));
        $catalog->addRole($catalogManager);
        $em->persist($catalog);

        $em->flush();
    }

    // ===================================================================
    //  Password reset
    // ===================================================================

    #[Test]
    public function passwordResetRoundTripLetsUserLoginWithNewPassword(): void
    {
        $token = $this->requestPasswordResetToken(self::ADMIN_EMAIL);

        $client = static::createClient();
        $client->request('POST', '/api/auth/password-reset/confirm', [
            'json' => ['token' => $token, 'password' => 'new-strong-password'],
        ]);
        self::assertResponseStatusCodeSame(200);

        // Old password no longer works; the new one does.
        self::assertSame(401, $this->loginStatus(self::ADMIN_EMAIL, self::ADMIN_PASSWORD));
        self::assertSame(200, $this->loginStatus(self::ADMIN_EMAIL, 'new-strong-password'));
    }

    #[Test]
    public function passwordResetTokenIsSingleUse(): void
    {
        $token = $this->requestPasswordResetToken(self::ADMIN_EMAIL);

        $first = static::createClient();
        $first->request('POST', '/api/auth/password-reset/confirm', [
            'json' => ['token' => $token, 'password' => 'first-password-x'],
        ]);
        self::assertResponseStatusCodeSame(200);

        // Replaying the same token must be refused.
        $second = static::createClient();
        $second->request('POST', '/api/auth/password-reset/confirm', [
            'json' => ['token' => $token, 'password' => 'second-password-x'],
        ]);
        self::assertResponseStatusCodeSame(400);

        // The second password must NOT have taken effect.
        self::assertSame(401, $this->loginStatus(self::ADMIN_EMAIL, 'second-password-x'));
        self::assertSame(200, $this->loginStatus(self::ADMIN_EMAIL, 'first-password-x'));
    }

    #[Test]
    public function expiredPasswordResetTokenIsRejected(): void
    {
        $token = $this->requestPasswordResetToken(self::ADMIN_EMAIL);
        $this->expireRow('password_reset_tokens', $this->hash($token));

        $client = static::createClient();
        $client->request('POST', '/api/auth/password-reset/confirm', [
            'json' => ['token' => $token, 'password' => 'late-password-xx'],
        ]);
        self::assertResponseStatusCodeSame(400);

        // The expired-token password must NOT have taken effect.
        self::assertSame(401, $this->loginStatus(self::ADMIN_EMAIL, 'late-password-xx'));
        self::assertSame(200, $this->loginStatus(self::ADMIN_EMAIL, self::ADMIN_PASSWORD));
    }

    #[Test]
    public function passwordResetConfirmDoesNotLeakToken(): void
    {
        $token = $this->requestPasswordResetToken(self::ADMIN_EMAIL);

        $client = static::createClient();
        $client->request('POST', '/api/auth/password-reset/confirm', [
            'json' => ['token' => $token, 'password' => 'leak-check-pw-1'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $body = $client->getResponse()?->toArray(throw: false) ?? [];
        self::assertArrayNotHasKey('token', $body);
        self::assertArrayNotHasKey('token_dev_only', $body);
    }

    #[Test]
    public function unknownPasswordResetTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/password-reset/confirm', [
            'json' => ['token' => bin2hex(random_bytes(32)), 'password' => 'whatever-pw-12'],
        ]);
        // Token not found → 404 (PasswordResetController maps RuntimeException).
        self::assertResponseStatusCodeSame(404);
    }

    // ===================================================================
    //  Invitation / magic link
    // ===================================================================

    #[Test]
    public function nonAdminCannotCreateInvitation(): void
    {
        // Escalation guard: only an admin (user.admin) may mint a magic-link
        // invitation. A Catalog Manager must be refused.
        $client = $this->clientFor(self::CATALOG_EMAIL);
        $client->request('POST', '/api/invitations', [
            'json' => ['email' => 'forbidden@example.com', 'role_code' => 'viewer'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function invitationRoundTripOnboardsUserAndAllowsLogin(): void
    {
        $token = $this->createInvitationToken('invitee@example.com', 'viewer');

        $client = static::createClient();
        $client->request('POST', '/api/invitations/'.$token.'/accept', [
            'json' => ['password' => 'invitee-password-1'],
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(200, $this->loginStatus('invitee@example.com', 'invitee-password-1'));
    }

    #[Test]
    public function invitationTokenIsSingleUse(): void
    {
        $token = $this->createInvitationToken('once@example.com', 'viewer');

        $first = static::createClient();
        $first->request('POST', '/api/invitations/'.$token.'/accept', [
            'json' => ['password' => 'first-accept-pw-1'],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Second acceptance of the same token must be refused.
        $second = static::createClient();
        $second->request('POST', '/api/invitations/'.$token.'/accept', [
            'json' => ['password' => 'second-accept-pw1'],
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function expiredInvitationIsRejectedOnAccept(): void
    {
        $token = $this->createInvitationToken('expired@example.com', 'viewer');
        $this->expireRow('invitations', $this->hash($token));

        $client = static::createClient();
        $client->request('POST', '/api/invitations/'.$token.'/accept', [
            'json' => ['password' => 'too-late-accept-1'],
        ]);
        self::assertResponseStatusCodeSame(400);

        // No account must have been created from the expired invitation.
        $this->em()->clear();
        $created = self::getContainer()->get(UserRepositoryInterface::class)
            ->findByEmail('expired@example.com');
        self::assertNull($created, 'An expired invitation must not create a user.');
    }

    #[Test]
    public function expiredInvitationReportsExpiredOnVerify(): void
    {
        $token = $this->createInvitationToken('verify-exp@example.com', 'viewer');
        $this->expireRow('invitations', $this->hash($token));

        $client = static::createClient();
        $client->request('GET', '/api/invitations/'.$token.'/verify');
        self::assertResponseStatusCodeSame(410);
    }

    #[Test]
    public function validInvitationReportsValidOnVerify(): void
    {
        $token = $this->createInvitationToken('verify-ok@example.com', 'viewer');

        $client = static::createClient();
        $client->request('GET', '/api/invitations/'.$token.'/verify');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function invitationAcceptDoesNotLeakToken(): void
    {
        $token = $this->createInvitationToken('leak-inv@example.com', 'viewer');

        $client = static::createClient();
        $client->request('POST', '/api/invitations/'.$token.'/accept', [
            'json' => ['password' => 'leak-inv-pw-readable'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $body = $client->getResponse()?->toArray(throw: false) ?? [];
        self::assertArrayNotHasKey('token', $body);
        self::assertArrayNotHasKey('token_dev_only', $body);
    }

    // ===================================================================
    //  Recovery e-mail links (#2827)
    // ===================================================================
    //
    // Both flows above drive the API directly, which is exactly why the
    // shipped e-mails could address routes that never existed and CI stayed
    // green: the invitee clicked the button, Caddy handed the non-/api path
    // to the SPA, the catch-all route redirected to /dashboard and — with no
    // session — on to /login. The recipient saw a bare login screen and the
    // single-use token was burnt for nothing. These two tests pin the link
    // shape to the SPA routes that serve it (App.tsx).

    #[Test]
    public function invitationEmailPointsAtTheAcceptInvitationSpaRoute(): void
    {
        $token = $this->createInvitationToken('link-check@example.com', 'viewer');

        $message = self::getMailerMessage();
        self::assertNotNull($message, 'Creating an invitation must send an e-mail.');
        self::assertEmailAddressContains($message, 'To', 'link-check@example.com');
        self::assertEmailHtmlBodyContains($message, '/accept-invitation?token='.$token);
        self::assertEmailHtmlBodyNotContains($message, '/invitations/'.$token.'/accept');
    }

    #[Test]
    public function passwordResetEmailPointsAtThePasswordResetSpaRoute(): void
    {
        $token = $this->requestPasswordResetToken(self::ADMIN_EMAIL);

        $message = self::getMailerMessage();
        self::assertNotNull($message, 'Requesting a reset for a known address must send an e-mail.');
        self::assertEmailAddressContains($message, 'To', self::ADMIN_EMAIL);
        self::assertEmailHtmlBodyContains($message, '/password-reset?token='.$token);
        self::assertEmailHtmlBodyNotContains($message, '/password-reset/'.$token);
    }

    // ===================================================================
    //  Helpers
    // ===================================================================

    /**
     * Drives POST /password-reset/request and returns the dev-mode plaintext
     * token (present because APP_ENV=test).
     */
    private function requestPasswordResetToken(string $email): string
    {
        // The per-email reset limiter (5/15min) is keyed by address, so the
        // per-test setUp (which only clears the per-IP bucket) cannot reach it;
        // clear this email's bucket so every /request returns the always-200
        // anti-enum response rather than spilling into a 429 across the many
        // password-reset flow tests in this class.
        self::getContainer()->get('limiter.password_reset_email')->create($email)->reset();

        $client = static::createClient();
        $client->request('POST', '/api/auth/password-reset/request', [
            'json' => ['email' => $email],
        ]);
        self::assertResponseStatusCodeSame(200);

        $body = $client->getResponse()?->toArray() ?? [];
        $token = $body['token_dev_only'] ?? null;
        \assert(\is_string($token) && '' !== $token);

        return $token;
    }

    /**
     * Drives POST /api/invitations (admin) and returns the dev-mode token.
     */
    private function createInvitationToken(string $email, string $roleCode): string
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('POST', '/api/invitations', [
            'json' => ['email' => $email, 'role_code' => $roleCode],
        ]);
        self::assertResponseStatusCodeSame(201);

        $body = $client->getResponse()?->toArray() ?? [];
        $token = $body['token_dev_only'] ?? null;
        \assert(\is_string($token) && '' !== $token);

        return $token;
    }

    private function loginStatus(string $email, string $password): int
    {
        // Login is throttled per IP — reset before each attempt so multiple
        // checks in one test don't trip the limiter.
        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        ]);

        return $client->getResponse()?->getStatusCode() ?? 0;
    }

    private function expireRow(string $table, string $tokenHash): void
    {
        $em = $this->em();
        $em->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET expires_at = :past WHERE token_hash = :hash', $table),
            ['past' => '1999-01-01 00:00:00', 'hash' => $tokenHash],
        );
        $em->clear();
    }

    private function hash(string $plaintext): string
    {
        return self::getContainer()->get(MagicLinkTokenHasher::class)->hash($plaintext);
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
