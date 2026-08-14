<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Application\RbacSeeder;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * #2875 — a tenant created through the admin API must come up usable.
 *
 * `SuperAdminTenantWriteController` seeded the PRD role templates and
 * stopped there. The built-in ObjectTypes, system attributes and default
 * menu were seeded only by `AppFixtures`, so the first tenant an operator
 * provisioned through the UI had no Product type at all — its owner opened
 * Products and was told to run the catalog seeder.
 *
 * The regression is invisible from the response: creation returned 201 and
 * the tenant row was fine. Only the ObjectType count gives it away, which
 * is what this pins.
 */
final class TenantProvisioningBootstrapTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string HOME_TENANT = 'acme';
    private const string OPERATOR_EMAIL = 'ops@platform.localhost';
    private const string NEW_TENANT_CODE = 'freshly-provisioned';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $platformOperator = $roles->findGlobalByCode(RbacMatrix::ROLE_PLATFORM_OPERATOR);
        \assert(null !== $platformOperator);

        $home = new Tenant(self::HOME_TENANT, 'Acme Industries');
        $em->persist($home);
        $em->flush();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($home, self::OPERATOR_EMAIL, '');
        $operator = new User($home, self::OPERATOR_EMAIL, $hasher->hashPassword($stub, 'changeme'));
        $operator->addRole($platformOperator);
        $em->persist($operator);
        $em->flush();
    }

    #[Test]
    public function aProvisionedTenantGetsItsBuiltInObjectTypes(): void
    {
        $client = $this->operatorClient();

        $client->request('POST', '/api/admin/tenants', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'code' => self::NEW_TENANT_CODE,
                'name' => 'Freshly Provisioned',
                'owner_email' => 'owner@freshly-provisioned.localhost',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(201);

        $tenant = self::getContainer()->get(TenantRepositoryInterface::class)->findByCode(self::NEW_TENANT_CODE);
        self::assertInstanceOf(Tenant::class, $tenant);

        $objectTypes = self::getContainer()->get(ObjectTypeRepositoryInterface::class);

        // The three kinds the sugar paths (/api/products, /api/categories,
        // /api/assets) resolve against. Without them the owner's first click
        // lands on "built-in product ObjectType not found in this tenant".
        foreach ([ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset] as $kind) {
            self::assertNotNull(
                $objectTypes->findBuiltInByKind($kind, $tenant),
                \sprintf('a provisioned tenant must own the built-in %s type', $kind->value),
            );
        }
    }

    private function operatorClient(): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::OPERATOR_EMAIL);
        \assert($user instanceof User);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return static::createClient([], ['headers' => ['authorization' => 'Bearer '.$token]]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
