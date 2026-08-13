<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * #2845 — the per-kind sugar paths (`/api/categories`, `/api/assets`,
 * `/api/asset_storage`) follow the PRD §3.2 codes the roles actually hold.
 *
 * Until now all three asked the kind-blind `is_granted('READ', CatalogObject::class)`
 * — the same question the poly-kind `/api/objects` asks — so no PRD role
 * could read them: opening that attribute would have opened every kind's
 * list at once. Products dodged it in #2416 with `READ_PRODUCT`; this gives
 * categories and assets the same treatment.
 *
 * `approver` is the control group throughout: it holds `products.view` and
 * neither `categories.view` nor `multimedia.view`, so it is what
 * distinguishes "the gate reads the kind" from "any catalog permission gets
 * waved through".
 */
final class KindSugarPathAccessTest extends CatalogApiTestCase
{
    private const string CATALOG_EMAIL = 'sugar-catalog@demo.localhost';
    private const string APPROVER_EMAIL = 'sugar-approver@demo.localhost';

    #[Test]
    #[TestWith(['/api/categories'])]
    #[TestWith(['/api/assets'])]
    #[TestWith(['/api/asset_storage'])]
    public function catalogRoleReadsTheKindItHasPermissionFor(string $path): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');

        $this->authenticatedClient(self::CATALOG_EMAIL)->request('GET', $path);

        self::assertResponseIsSuccessful($path.' must be readable by a catalog role');
    }

    #[Test]
    #[TestWith(['/api/categories'])]
    #[TestWith(['/api/assets'])]
    #[TestWith(['/api/asset_storage'])]
    public function aProductOnlyRoleReachesNoneOfThem(string $path): void
    {
        $this->givenUserWithRole(self::APPROVER_EMAIL, 'approver');

        $this->authenticatedClient(self::APPROVER_EMAIL)->request('GET', $path);

        self::assertResponseStatusCodeSame(403, $path.' needs its own kind permission');
    }

    /**
     * The whole point of the kind-specific attributes: widening the sugar
     * paths must not widen the poly-kind union they share a subject with.
     */
    #[Test]
    public function noneOfThisOpensTheGenericObjectList(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');

        $this->authenticatedClient(self::CATALOG_EMAIL)->request('GET', '/api/objects');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Reading a kind is not writing it — `approver` cannot create categories
     * and neither can a role that only views them.
     */
    #[Test]
    public function readingCategoriesDoesNotGrantCreatingThem(): void
    {
        $this->givenUserWithRole(self::APPROVER_EMAIL, 'approver');

        $this->authenticatedClient(self::APPROVER_EMAIL)->request('POST', '/api/categories', [
            'json' => ['code' => 'sneaky-cat', 'label' => ['pl' => 'Sneaky']],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function givenUserWithRole(string $email, string $roleCode): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        // ADR-0034 — addRole() is the single write path for assignments.
        $user->addRole($role);
        $this->em()->persist($user);
        $this->em()->flush();
        $this->em()->clear();
    }
}
