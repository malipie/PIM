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
 * RBAC-P3-001 (#664) — integration coverage for the EndpointGuardListener
 * against the dev/test-only TestGuardedController fixture.
 *
 * Three scenarios exercise every branch of the guard:
 *  - GET /api/_test/guarded without JWT → firewall 401 (guard never reached)
 *  - GET /api/_test/guarded as viewer (lacks `object.delete`) → guard returns
 *    403 + RFC 7807 problem details with `permission_required=object.delete`
 *  - GET /api/_test/guarded as super_admin (has every permission) → 200
 *
 * The fixture controller uses `object.delete` because the seeded matrix
 * grants it to super_admin but withholds it from viewer (read-only),
 * letting one endpoint cover both branches.
 */
final class EndpointGuardSmokeTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string VIEWER_EMAIL = 'viewer@demo.localhost';

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

        // #2837 — viewer is a tenant role now, so it exists only after the
        // tenant does and only once the PRD templates are seeded into it.
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $viewer = $roles->findByCode(RbacMatrix::ROLE_VIEWER, $tenant);
        \assert(null !== $viewer);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $adminStub = new User($tenant, self::ADMIN_EMAIL, '');
        $admin = new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($adminStub, 'changeme'));
        $admin->addRole($superAdmin);
        $em->persist($admin);

        $viewerStub = new User($tenant, self::VIEWER_EMAIL, '');
        $viewerUser = new User($tenant, self::VIEWER_EMAIL, $hasher->hashPassword($viewerStub, 'changeme'));
        $viewerUser->addRole($viewer);
        $em->persist($viewerUser);

        $em->flush();
    }

    #[Test]
    public function noJwtReturns401(): void
    {
        static::createClient()->request('GET', '/api/_test/guarded');

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function viewerUserGets403WithPermissionRequiredField(): void
    {
        $client = $this->clientFor(self::VIEWER_EMAIL);
        $client->request('GET', '/api/_test/guarded');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');

        $response = $client->getResponse();
        \assert(null !== $response);
        $body = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('object.delete', $body['permission_required'] ?? null);
        self::assertSame(403, $body['status'] ?? null);
        self::assertSame('Permission denied', $body['title'] ?? null);
    }

    #[Test]
    public function superAdminPassesGuard(): void
    {
        $client = $this->clientFor(self::ADMIN_EMAIL);
        $client->request('GET', '/api/_test/guarded');

        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        \assert(null !== $response);
        $body = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['ok' => true, 'guard' => 'passed'], $body);
    }

    #[Test]
    public function noPermissionRequiredEndpointSkipsCheck(): void
    {
        // Viewer lacks `object.delete` but the /public route opts out of
        // permission checks via #[NoPermissionRequired], so the guard
        // lets it through to the controller.
        $client = $this->clientFor(self::VIEWER_EMAIL);
        $client->request('GET', '/api/_test/public');

        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        \assert(null !== $response);
        $body = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['ok' => true, 'guard' => 'skipped'], $body);
    }

    private function clientFor(string $email): \ApiPlatform\Symfony\Bundle\Test\Client
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
