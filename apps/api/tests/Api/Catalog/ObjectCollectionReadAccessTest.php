<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * #2848 — the poly-kind `GET /api/objects` collection is what the admin's
 * universal list view actually browses (`?objectType=<uuid>`), not the
 * `/api/products` sugar path. #2841 gave catalog roles the schema behind the
 * list; this is the data behind it.
 *
 * The symptom was a Catalog Manager opening `/products` in a tenant with
 * 1322 products and reading "0 wyników", because the collection asked for a
 * kind-blind `READ` that no PRD role holds.
 *
 * What keeps the widening honest is the pair of denials below: the unscoped
 * union still needs the broad legacy grant, and a role that may read one
 * kind must not reach another kind through the same endpoint. Without those
 * two, this gate would be the escalation `ProductCreatePermissionApiTest`
 * exists to catch.
 */
final class ObjectCollectionReadAccessTest extends CatalogApiTestCase
{
    private const string CATALOG_EMAIL = 'objects-catalog@demo.localhost';
    private const string APPROVER_EMAIL = 'objects-approver@demo.localhost';

    #[Test]
    public function catalogRoleBrowsesTheListItsPageActuallyRequests(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');
        $typeId = $this->objectTypeIdFor(ObjectKind::Product);

        $this->authenticatedClient(self::CATALOG_EMAIL)
            ->request('GET', '/api/objects?objectType='.$typeId);

        self::assertResponseIsSuccessful('the universal product list must load for a catalog role');
    }

    /**
     * No `objectType` means "every kind at once", which is a genuinely
     * broader question than any single PRD code answers.
     */
    #[Test]
    public function theUnscopedUnionStillNeedsTheBroadGrant(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');

        $this->authenticatedClient(self::CATALOG_EMAIL)->request('GET', '/api/objects');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * `approver` holds `products.view` and not `categories.view` — so it is
     * the shape that proves the gate reads the kind instead of waving any
     * catalog permission through.
     */
    #[Test]
    public function readingOneKindDoesNotOpenAnother(): void
    {
        $this->givenUserWithRole(self::APPROVER_EMAIL, 'approver');
        $client = $this->authenticatedClient(self::APPROVER_EMAIL);

        $client->request('GET', '/api/objects?objectType='.$this->objectTypeIdFor(ObjectKind::Product));
        self::assertResponseIsSuccessful('approver holds products.view');

        $client->request('GET', '/api/objects?objectType='.$this->objectTypeIdFor(ObjectKind::Category));
        self::assertResponseStatusCodeSame(403, 'approver holds no categories.view');
    }

    /**
     * An unknown id is the same answer as a borrowed one: the TenantFilter
     * hides other tenants' types, so both resolve to "no kind" and deny.
     */
    #[Test]
    public function anUnknownObjectTypeDenies(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');

        $this->authenticatedClient(self::CATALOG_EMAIL)
            ->request('GET', '/api/objects?objectType=019f0000-0000-7000-8000-000000000000');

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
