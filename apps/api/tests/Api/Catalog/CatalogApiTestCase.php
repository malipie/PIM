<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Shared scaffolding for Catalog ApiTestCase suites (#41 / 0.4.1).
 *
 * Seeds RBAC + tenant + super_admin user + built-in ObjectTypes so each
 * concrete test only needs to assert on the API surface. JWT minting via
 * `JWTTokenManagerInterface` skips the round-trip to `/api/auth/login`
 * — single kernel boot per test (lessons #0.0.4).
 */
abstract class CatalogApiTestCase extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    protected const string TENANT_CODE = 'demo';
    protected const string ADMIN_EMAIL = 'admin@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        // RBAC-P6-005/006/007 retrofit — Phase 6 retrofit gates every
        // controller method with PRD §3.2 granular codes (products.add,
        // modeling.attribute_groups.add_edit, etc.). RbacSeeder only
        // emits the legacy RbacMatrix 4-action × 19-resource set, so
        // these tests need the PRD permission catalogue + tenant_owner
        // role assigned to the test admin to clear EndpointGuardListener.
        self::getContainer()->get(PrdPermissionSeeder::class)->seed();
        $em->flush();

        $superAdmin = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('tenant_owner', $tenant);
        \assert(null !== $tenantOwner);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::ADMIN_EMAIL, '', ['ROLE_USER']);
        $admin = new User($tenant, self::ADMIN_EMAIL, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $admin->addRole($superAdmin);
        $admin->addRole($tenantOwner);
        $em->persist($admin);
        $em->flush();

        // Built-in ObjectTypes (`product`, `category`, `asset`) for the tenant.
        self::getContainer()->get(BuiltInObjectTypeSeeder::class)->seed($tenant);
    }

    protected function authenticatedClient(string $email = self::ADMIN_EMAIL): \ApiPlatform\Symfony\Bundle\Test\Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    protected function objectTypeIdFor(ObjectKind $kind): string
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $type = self::getContainer()
            ->get(\App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind($kind, $tenant);
        \assert(null !== $type);

        return $type->getId()->toRfc4122();
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
