<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\RefreshToken;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RefreshTokenRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * DP-02 (#2032) — `POST /api/users/{id}/password` (admin sets a user's
 * password from the panel).
 *
 * Invariants:
 *  - happy path: 204, the new password verifies, password_change_required
 *    is set, and every active refresh token of the target is revoked,
 *  - self-target refused with 409 (use /api/me/change-password),
 *  - password shorter than 12 chars → 400,
 *  - cross-tenant target → 404 (boundary never distinguishable),
 *  - non-admin caller → 403 (same `user.admin` gate as the users CRUD).
 */
final class UserSetPasswordControllerTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_A_CODE = 'demo';
    private const string TENANT_B_CODE = 'other';

    private const string ADMIN_A_EMAIL = 'admin@demo.localhost';
    private const string TARGET_A_EMAIL = 'warehouse@demo.localhost';
    private const string CATALOG_A_EMAIL = 'catalog@demo.localhost';
    private const string ADMIN_B_EMAIL = 'admin@other.localhost';

    private const string NEW_PASSWORD = 'brand-new-secret-42';

    private string $adminAUserId = '';
    private string $targetUserId = '';
    private string $adminBUserId = '';

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenantA = new Tenant(self::TENANT_A_CODE, 'Demo Tenant');
        $tenantB = new Tenant(self::TENANT_B_CODE, 'Other Tenant');
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenantA);
        // #2837 — catalog_manager is a tenant role now.
        $catalogManager = $roles->findByCode(RbacMatrix::ROLE_CATALOG_MANAGER, $tenantA);
        \assert(null !== $catalogManager);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $adminA = $this->makeUser($tenantA, self::ADMIN_A_EMAIL, $hasher);
        $adminA->addRole($superAdmin);
        $em->persist($adminA);

        $target = $this->makeUser($tenantA, self::TARGET_A_EMAIL, $hasher);
        $target->addRole($catalogManager);
        $em->persist($target);

        $catalog = $this->makeUser($tenantA, self::CATALOG_A_EMAIL, $hasher);
        $catalog->addRole($catalogManager);
        $em->persist($catalog);

        $adminB = $this->makeUser($tenantB, self::ADMIN_B_EMAIL, $hasher);
        $adminB->addRole($superAdmin);
        $em->persist($adminB);

        $em->flush();

        $this->adminAUserId = $adminA->getId()->toRfc4122();
        $this->targetUserId = $target->getId()->toRfc4122();
        $this->adminBUserId = $adminB->getId()->toRfc4122();
    }

    #[Test]
    public function setsPasswordFlagsChangeRequiredAndRevokesTokens(): void
    {
        // Seed an active refresh token for the target — the reset must kill it.
        $tokenId = $this->seedRefreshToken($this->targetUserId);

        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->targetUserId.'/password', [
            'json' => ['password' => self::NEW_PASSWORD],
        ]);

        self::assertResponseStatusCodeSame(204);

        $this->em()->clear();
        $users = self::getContainer()->get(UserRepositoryInterface::class);
        $target = $users->findById(Uuid::fromString($this->targetUserId));
        \assert(null !== $target);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($target, self::NEW_PASSWORD));
        self::assertTrue($target->isPasswordChangeRequired());

        $token = self::getContainer()->get(RefreshTokenRepositoryInterface::class)->findById($tokenId);
        \assert(null !== $token);
        self::assertTrue($token->isRevoked());
    }

    #[Test]
    public function forcePasswordChangeFalseSkipsTheFlag(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->targetUserId.'/password', [
            'json' => ['password' => self::NEW_PASSWORD, 'force_password_change' => false],
        ]);

        self::assertResponseStatusCodeSame(204);

        $this->em()->clear();
        $target = self::getContainer()
            ->get(UserRepositoryInterface::class)
            ->findById(Uuid::fromString($this->targetUserId));
        \assert(null !== $target);
        self::assertFalse($target->isPasswordChangeRequired());
    }

    #[Test]
    public function selfResetReturns409(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->adminAUserId.'/password', [
            'json' => ['password' => self::NEW_PASSWORD],
        ]);

        self::assertResponseStatusCodeSame(409);
        $body = $this->decodeResponse($client);
        self::assertSame('self_reset', $body['code'] ?? null);
    }

    #[Test]
    public function shortPasswordReturns400(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->targetUserId.'/password', [
            'json' => ['password' => 'too-short'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function crossTenantTargetReturns404(): void
    {
        $client = $this->clientFor(self::ADMIN_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->adminBUserId.'/password', [
            'json' => ['password' => self::NEW_PASSWORD],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function nonAdminReceives403(): void
    {
        $client = $this->clientFor(self::CATALOG_A_EMAIL);
        $client->request('POST', '/api/users/'.$this->targetUserId.'/password', [
            'json' => ['password' => self::NEW_PASSWORD],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function seedRefreshToken(string $userId): Uuid
    {
        $users = self::getContainer()->get(UserRepositoryInterface::class);
        $user = $users->findById(Uuid::fromString($userId));
        \assert(null !== $user);

        $token = new RefreshToken(
            $user->getTenant()->getId(),
            $user->getId(),
            Uuid::v7(),
            hash('sha256', 'dp02-test-token'),
            new DateTimeImmutable(),
            new DateTimeImmutable('+30 days'),
        );
        self::getContainer()->get(RefreshTokenRepositoryInterface::class)->save($token);

        return $token->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Client $client): array
    {
        $response = $client->getResponse();
        \assert(null !== $response);

        /** @var array<string, mixed> $payload */
        $payload = $response->toArray(throw: false);

        return $payload;
    }

    private function makeUser(Tenant $tenant, string $email, UserPasswordHasherInterface $hasher): User
    {
        $stub = new User($tenant, $email, '');

        return new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'));
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
