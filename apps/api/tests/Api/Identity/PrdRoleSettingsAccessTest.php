<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2874 — the owner of a tenant created through the admin UI must be able
 * to open the settings surface.
 *
 * Those endpoints asked for the legacy `{resource}.{action}` catalogue
 * (`user.admin`, `user.read`) while `SeedTenantPrdRolesService` grants the
 * PRD §3.2 business codes (`settings.users.manage`, `settings.roles.manage`,
 * …). Nobody noticed because the only real principal was a bootstrap owner
 * carrying legacy roles; the first tenant provisioned through the UI got a
 * 403 on every settings screen, and the Subscription page told its own
 * tenant owner that "only the tenant Owner" may look at it.
 *
 * The `viewer` cases are the half that keeps this honest: widening a gate
 * to accept a second catalogue must not turn it into no gate at all.
 */
final class PrdRoleSettingsAccessTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string OWNER_EMAIL = 'prd-owner@demo.localhost';
    private const string VIEWER_EMAIL = 'prd-viewer@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        self::getContainer()->get(PrdPermissionSeeder::class)->seed();
        $em->flush();

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach ([[self::OWNER_EMAIL, 'tenant_owner'], [self::VIEWER_EMAIL, 'viewer']] as [$email, $code]) {
            $role = $roles->findByCode($code, $tenant);
            \assert(null !== $role, $code.' must be seeded per tenant');

            $stub = new User($tenant, $email, '');
            $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'));
            $user->addRole($role);
            $em->persist($user);
        }

        $em->flush();
        $em->clear();
    }

    /**
     * Exactly the screens the operator reported as dead.
     */
    #[Test]
    #[TestWith(['/api/users'])]
    #[TestWith(['/api/roles'])]
    #[TestWith(['/api/permissions'])]
    #[TestWith(['/api/api-tokens'])]
    #[TestWith(['/api/sso/providers'])]
    #[TestWith(['/api/tenant'])]
    public function aPrdTenantOwnerReachesTheSettingsSurface(string $path): void
    {
        $this->clientFor(self::OWNER_EMAIL)->request('GET', $path);

        self::assertResponseIsSuccessful($path.' must open for a PRD tenant owner');
    }

    /**
     * The caller's own context — no permission beyond being logged in, the
     * same shape as /api/me. A 403 here left every member of a PRD tenant
     * with the fallback menu and a red console on every render.
     */
    #[Test]
    #[TestWith(['/api/menu_configuration/effective'])]
    #[TestWith(['/api/users/me/filter-favorites'])]
    public function ownContextReadsNeedNothingBeyondAuthentication(string $path): void
    {
        $this->clientFor(self::VIEWER_EMAIL)->request('GET', $path);

        self::assertResponseIsSuccessful($path.' is the caller\'s own context');
    }

    /**
     * The control: `viewer` holds neither catalogue's settings codes, so
     * every one of these must stay refused. Without this, accepting a
     * second catalogue could quietly become accepting anyone.
     */
    #[Test]
    #[TestWith(['/api/users'])]
    #[TestWith(['/api/roles'])]
    #[TestWith(['/api/permissions'])]
    #[TestWith(['/api/sso/providers'])]
    #[TestWith(['/api/tenant'])]
    public function aViewerStillReachesNoneOfIt(string $path): void
    {
        $this->clientFor(self::VIEWER_EMAIL)->request('GET', $path);

        self::assertResponseStatusCodeSame(403, $path.' must stay gated');
    }

    /**
     * API tokens are the one pair that is NOT equivalent: `user.read` is a
     * broad legacy read, while the PRD side is `api_tokens.own.crud` — the
     * caller's own tokens. Both templates carry it, so viewer passes here
     * on purpose, and the tenant-wide view stays behind
     * `api_tokens.all.view_revoke` inside the controller.
     */
    #[Test]
    public function apiTokensFollowTheOwnTokensGrant(): void
    {
        $this->clientFor(self::VIEWER_EMAIL)->request('GET', '/api/api-tokens');

        self::assertResponseIsSuccessful();
    }

    private function clientFor(string $email): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert($user instanceof User);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return static::createClient([], ['headers' => ['authorization' => 'Bearer '.$token]]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
