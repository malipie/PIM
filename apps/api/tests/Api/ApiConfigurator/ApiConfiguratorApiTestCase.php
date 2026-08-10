<?php

declare(strict_types=1);

namespace App\Tests\Api\ApiConfigurator;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
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
 * Shared scaffolding for ApiConfigurator ApiTestCase suites.
 *
 * Mirrors {@see \App\Tests\Api\Catalog\CatalogApiTestCase} — seeds the
 * RBAC matrix, a `demo` tenant and a super_admin user so each concrete
 * test asserts on the public API surface only.
 */
abstract class ApiConfiguratorApiTestCase extends ApiTestCase
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
        // RBAC-P6 retrofit — same scaffolding as CatalogApiTestCase.
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
    }

    protected function authenticatedClient(string $email = self::ADMIN_EMAIL): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
