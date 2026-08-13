<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * #2838 — a catalog role must be able to READ the schema that describes
 * the data it may read, and must still be unable to CHANGE it.
 *
 * The reported symptom was a Catalog Manager opening `/products` and being
 * told "Built-in product ObjectType not found in this tenant — run the
 * catalog seeder". The data was there; the role simply could not fetch the
 * type definition the list needs to know which columns to draw. Cause: API
 * Platform asks `is_granted('READ', …)` in uppercase, the PRD voters only
 * spoke lowercase verbs, so the legacy voter was the only one voting and it
 * demands codes (`object_type.read`, `category.read`) that no PRD role holds.
 *
 * Both directions are pinned here on purpose. Widening reads is easy to
 * overshoot into "and now they can edit the model too" — the write cases
 * below are what keeps that honest.
 */
final class SchemaMetadataReadAccessTest extends CatalogApiTestCase
{
    private const string CATALOG_EMAIL = 'catalog-metadata@demo.localhost';

    #[Test]
    #[TestWith(['/api/object_types'])]
    #[TestWith(['/api/attributes'])]
    #[TestWith(['/api/products'])]
    public function catalogRoleReadsTheSchemaItsListsDependOn(string $path): void
    {
        $this->givenCatalogManager();

        $this->authenticatedClient(self::CATALOG_EMAIL)->request('GET', $path);

        self::assertResponseIsSuccessful($path.' must be readable by a catalog role');
    }

    #[Test]
    public function catalogRoleReadsTheListSchemaOfTheProductType(): void
    {
        $this->givenCatalogManager();
        $typeId = $this->objectTypeIdFor(ObjectKind::Product);

        $this->authenticatedClient(self::CATALOG_EMAIL)
            ->request('GET', '/api/object_types/'.$typeId.'/list-schema');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function catalogRoleStillCannotChangeTheSchema(): void
    {
        $this->givenCatalogManager();
        $client = $this->authenticatedClient(self::CATALOG_EMAIL);

        $client->request('POST', '/api/object_types', [
            'json' => ['code' => 'sneaky', 'kind' => 'custom', 'label' => ['pl' => 'Sneaky']],
        ]);
        self::assertResponseStatusCodeSame(403, 'creating an ObjectType stays a modeling action');

        $client->request('POST', '/api/attributes', [
            'json' => ['code' => 'sneaky_attr', 'type' => 'text', 'label' => ['pl' => 'Sneaky']],
        ]);
        self::assertResponseStatusCodeSame(403, 'creating an Attribute stays a modeling action');

        $typeId = $this->objectTypeIdFor(ObjectKind::Product);
        $client->request('DELETE', '/api/object_types/'.$typeId);
        self::assertResponseStatusCodeSame(403, 'deleting a built-in type stays refused');
    }

    /**
     * A user holding exactly the PRD `catalog_manager` template — the shape
     * that produced the report.
     */
    private function givenCatalogManager(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('catalog_manager', $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::CATALOG_EMAIL, '', ['ROLE_USER']);
        $user = new User(
            $tenant,
            self::CATALOG_EMAIL,
            $hasher->hashPassword($stub, 'changeme'),
            ['ROLE_USER'],
        );
        // ADR-0034 — addRole() is the single write path for assignments.
        $user->addRole($role);
        $this->em()->persist($user);
        $this->em()->flush();
        $this->em()->clear();
    }
}
