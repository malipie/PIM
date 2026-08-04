<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Presentation\Controller;

use App\Identity\Application\InvitationService;
use App\Identity\Application\MagicLinkTokenHasher;
use App\Identity\Application\PasswordResetService;
use App\Identity\Domain\Entity\Invitation;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\InvitationRepositoryInterface;
use App\Identity\Domain\Repository\PasswordResetTokenRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Domain\Repository\UserRoleRepositoryInterface;
use App\Identity\Presentation\Controller\InvitationActionsController;
use App\Identity\Presentation\Controller\InvitationController;
use App\Identity\Presentation\Controller\PasswordResetController;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * AUD-007 (#1577) — regression guard for the dev-only plaintext token leak.
 *
 * The password-reset + invitation endpoints are PUBLIC_ACCESS and used to
 * return the freshly minted 256-bit reset/invitation token in their JSON
 * body UNCONDITIONALLY. On any prod deploy that is a CRITICAL account
 * takeover: an attacker who knows a victim's email mints + reads a working
 * reset token, then confirms it (lesson #657/#658).
 *
 * The fix gates `token_dev_only` on the injected kernel environment via the
 * DevTokenExposure trait. These tests drive the real controller actions with
 * real services (their collaborators are mocked at the interface boundary) and
 * an explicit environment, so the prod-mode behaviour is proven WITHOUT
 * booting a prod kernel:
 *
 *   - env === 'prod'  → the `token_dev_only` key MUST be absent (the leak).
 *   - env === 'test'  → the key MUST be present (dev/test operator workflow
 *                       preserved until the mailer fully replaces it).
 *
 * Covers all three leak sites from the finding:
 *   PasswordResetController::request        (POST /api/auth/password-reset/request)
 *   InvitationController::create            (POST /api/invitations)
 *   InvitationActionsController::resend     (POST /api/invitations/{id}/resend)
 */
final class DevTokenExposureTest extends TestCase
{
    #[Test]
    #[DataProvider('leakSites')]
    public function prodEnvironmentOmitsTokenDevOnly(string $controllerKey): void
    {
        $body = $this->invoke($controllerKey, 'prod');

        self::assertArrayNotHasKey(
            'token_dev_only',
            $body,
            \sprintf('%s leaked token_dev_only in prod (account-takeover vector)', $controllerKey),
        );
    }

    /**
     * #2727 — the guard is an ALLOWLIST, not a denylist on 'prod': any
     * unexpected environment (staging, preprod, an APP_ENV typo) must be
     * treated like prod, or knowing a victim's email mints a readable
     * reset token there.
     */
    #[Test]
    #[DataProvider('unexpectedEnvironments')]
    public function unexpectedEnvironmentOmitsTokenDevOnly(string $environment): void
    {
        $body = $this->invoke('password_reset', $environment);

        self::assertArrayNotHasKey(
            'token_dev_only',
            $body,
            \sprintf("environment '%s' leaked token_dev_only (allowlist bypass)", $environment),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unexpectedEnvironments(): iterable
    {
        yield 'staging' => ['staging'];
        yield 'preprod' => ['preprod'];
        yield 'typo' => ['pord'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('leakSites')]
    public function nonProdEnvironmentKeepsTokenDevOnly(string $controllerKey): void
    {
        $body = $this->invoke($controllerKey, 'test');

        self::assertArrayHasKey(
            'token_dev_only',
            $body,
            \sprintf('%s dropped token_dev_only in test (broke dev workflow)', $controllerKey),
        );
        $token = $body['token_dev_only'];
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leakSites(): iterable
    {
        yield 'password-reset/request' => ['password_reset'];
        yield 'invitations/create' => ['invitation_create'];
        yield 'invitations/resend' => ['invitation_resend'];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoke(string $controllerKey, string $environment): array
    {
        $response = match ($controllerKey) {
            'password_reset' => $this->passwordResetController($environment)
                ->request(self::jsonRequest(['email' => 'victim@demo.localhost'])),
            'invitation_create' => $this->invitationCreateController($environment)
                ->create(self::jsonRequest(['email' => 'invitee@demo.localhost', 'role_code' => 'viewer'])),
            'invitation_resend' => $this->invitationActionsController($environment)
                ->resend('11111111-1111-1111-1111-111111111111'),
            default => self::fail('unknown controller key'),
        };

        return $this->decode($response);
    }

    private function passwordResetController(string $environment): PasswordResetController
    {
        $tenant = $this->tenant();

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($this->user($tenant));

        $service = new PasswordResetService(
            em: $this->createStub(EntityManagerInterface::class),
            tokens: $this->createStub(PasswordResetTokenRepositoryInterface::class),
            users: $users,
            tokenHasher: new MagicLinkTokenHasher(),
            passwordHasher: $this->createStub(UserPasswordHasherInterface::class),
            mailer: $this->createStub(MailerInterface::class),
            logger: new NullLogger(),
        );

        return new PasswordResetController(
            $service,
            $environment,
            $this->unlimitedLimiter(),
            $this->unlimitedLimiter(),
        );
    }

    private function invitationCreateController(string $environment): InvitationController
    {
        $tenant = $this->tenant();

        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findByCode')->willReturn($this->role($tenant));

        $service = $this->invitationService($roles, $this->createStub(InvitationRepositoryInterface::class));

        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $controller = new InvitationController($service, $tenantContext, $environment, $this->unlimitedLimiter());
        // AbstractController::getUser() reads from the security token storage
        // via the controller container; wire a minimal authenticated principal.
        $controller->setContainer($this->containerWithUser($this->user($tenant)));

        return $controller;
    }

    private function invitationActionsController(string $environment): InvitationActionsController
    {
        $tenant = $this->tenant();
        $caller = $this->user($tenant);

        $existing = $this->createStub(Invitation::class);
        $existing->method('getTenantId')->willReturn($tenant->getId());
        $existing->method('getEmail')->willReturn('invitee@demo.localhost');
        $existing->method('getAcceptedAt')->willReturn(null);
        $existing->method('getRevokedAt')->willReturn(null);
        $existing->method('getRoleId')->willReturn(Uuid::v7());

        $role = $this->role($tenant);

        $invitations = $this->createStub(InvitationRepositoryInterface::class);
        $invitations->method('findById')->willReturn($existing);

        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn($role);
        $roles->method('findByCode')->willReturn($role);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($caller);

        // The controller's revoke()+create() path delegates back into the
        // service, which re-reads the invitation via findById — share the
        // same repo mock so the service sees the existing invitation too.
        return new InvitationActionsController(
            $security,
            $invitations,
            $roles,
            $this->invitationService($roles, $invitations),
            $environment,
        );
    }

    private function invitationService(RoleRepositoryInterface $roles, InvitationRepositoryInterface $invitations): InvitationService
    {
        return new InvitationService(
            em: $this->createStub(EntityManagerInterface::class),
            invitations: $invitations,
            users: $this->createStub(UserRepositoryInterface::class),
            userRoles: $this->createStub(UserRoleRepositoryInterface::class),
            roles: $roles,
            tokenHasher: new MagicLinkTokenHasher(),
            passwordHasher: $this->createStub(UserPasswordHasherInterface::class),
            mailer: $this->createStub(MailerInterface::class),
            logger: new NullLogger(),
        );
    }

    /**
     * AUD-030 (W2-12) — the reset/invitation controllers now consume a rate
     * limiter before their side-effects. This leak-regression test exercises
     * the happy path, so it needs limiters that always accept. A real factory
     * over a fresh InMemoryStorage with a generous budget does exactly that
     * without mocking the (final) Reservation/RateLimit value objects.
     */
    private function unlimitedLimiter(): RateLimiterFactoryInterface
    {
        return new RateLimiterFactory(
            ['id' => 'test_unlimited', 'policy' => 'fixed_window', 'limit' => 1000, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function tenant(): Tenant
    {
        return new Tenant('demo', 'Demo');
    }

    private function user(Tenant $tenant): User
    {
        return new User($tenant, 'admin@demo.localhost', 'placeholder-hash');
    }

    private function role(Tenant $tenant): Role
    {
        return new Role('viewer', 'Viewer', $tenant);
    }

    private function containerWithUser(User $user): ContainerInterface
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return new class($tokenStorage) implements ContainerInterface {
            public function __construct(private readonly TokenStorage $tokenStorage)
            {
            }

            public function get(string $id): mixed
            {
                return 'security.token_storage' === $id ? $this->tokenStorage : null;
            }

            public function has(string $id): bool
            {
                return 'security.token_storage' === $id;
            }
        };
    }

    /**
     * @param array<string, string> $payload
     */
    private static function jsonRequest(array $payload): Request
    {
        return new Request(content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
