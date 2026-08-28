<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * An attribute detached from every ObjectType / AttributeGroup / Category is
 * invisible in the modeling UI, so any leftover `object_values` can never be
 * cleared by hand — yet the ON DELETE RESTRICT FK would block the attribute
 * delete forever. DeleteAttributeHandler resolves that dead-end by cascading
 * the orphaned values (rebuilding the `attributes_indexed` cache). When the
 * attribute is still reachable it stays blocked (detach first).
 */
final class DeleteOrphanedAttributeApiTest extends CatalogApiTestCase
{
    #[Test]
    public function deletesOrphanedAttributeTogetherWithItsValues(): void
    {
        $tenant = $this->tenant();
        $this->tenantContext()->set($tenant);
        $em = $this->em();
        $productType = $this->productType($tenant);

        $attribute = new Attribute('orphan_color', ['en' => 'Orphan color'], AttributeType::Text);
        $em->persist($attribute);
        $object = new CatalogObject($productType, 'ORPH-001');
        $object->forceStatus(CatalogObject::STATUS_PUBLISHED);
        $em->persist($object);
        $em->flush();

        // Value present but attribute attached to NOTHING — the orphaned state.
        $em->persist(new ObjectValue($object, $attribute, ['value' => 'taupe'], Provenance::Manual));
        $object->updateAttributeIndex(['orphan_color' => ['value' => 'taupe']]);
        $em->flush();

        $attributeId = $attribute->getId()->toRfc4122();
        $objectId = $object->getId()->toRfc4122();
        $this->tenantContext()->clear();

        $client = $this->authenticatedClient();
        $response = $client->request('DELETE', '/api/attributes/'.$attributeId);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        $em->clear();
        self::assertNull(
            self::getContainer()->get(AttributeRepositoryInterface::class)->findById(Uuid::fromString($attributeId)),
            'Orphaned attribute should be deleted.',
        );
        $remaining = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM object_values WHERE attribute_id = ?',
            [$attributeId],
        );
        self::assertSame(
            0,
            \is_scalar($remaining) ? (int) $remaining : -1,
            'Orphaned values should be cascade-deleted.',
        );
        $indexed = $em->getConnection()->fetchOne(
            'SELECT attributes_indexed FROM objects WHERE id = ?',
            [$objectId],
        );
        self::assertIsString($indexed);
        self::assertStringNotContainsString('orphan_color', $indexed, 'Cache must drop the deleted key.');
    }

    #[Test]
    public function blocksDeleteWhileStillAttachedToObjectType(): void
    {
        $tenant = $this->tenant();
        $this->tenantContext()->set($tenant);
        $em = $this->em();
        $productType = $this->productType($tenant);

        $attribute = new Attribute('attached_color', ['en' => 'Attached color'], AttributeType::Text);
        $em->persist($attribute);
        $object = new CatalogObject($productType, 'ATT-001');
        $object->forceStatus(CatalogObject::STATUS_PUBLISHED);
        $em->persist($object);
        $em->flush();

        $em->persist(new ObjectTypeAttribute($productType, $attribute, false, 0));
        $em->persist(new ObjectValue($object, $attribute, ['value' => 'navy'], Provenance::Manual));
        $em->flush();

        $attributeId = $attribute->getId()->toRfc4122();
        $this->tenantContext()->clear();

        $client = $this->authenticatedClient();
        $client->request('DELETE', '/api/attributes/'.$attributeId);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $em->clear();
        self::assertNotNull(
            self::getContainer()->get(AttributeRepositoryInterface::class)->findById(Uuid::fromString($attributeId)),
            'Attached attribute must NOT be deleted.',
        );
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function productType(Tenant $tenant): ObjectType
    {
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($type instanceof ObjectType);

        return $type;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
