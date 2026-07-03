<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\ObjectTypeService;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Exception\BuiltInObjectTypeException;
use App\Catalog\Domain\Exception\DisabledFeatureException;
use App\Catalog\Domain\Exception\ObjectTypeHasInstancesException;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Import\Domain\Entity\ImportProfile;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ObjectTypeServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;
    private Attribute $someAttribute;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $tenant = new Tenant('demo', 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        $this->tenant = $tenant;
        $this->tenantContext()->set($tenant);

        $attribute = new Attribute('name', ['pl' => 'Nazwa', 'en' => 'Name'], AttributeType::Text);
        $em->persist($attribute);
        $em->flush();

        $this->someAttribute = $attribute;
    }

    #[Test]
    public function createBuiltInProductTypeWorks(): void
    {
        $type = $this->serviceWithCustomEnabled(false)->create(
            'product',
            ObjectKind::Product,
            ['pl' => 'Produkt', 'en' => 'Product'],
            builtIn: true,
        );

        self::assertSame('product', $type->getCode());
        self::assertSame(ObjectKind::Product, $type->getKind());
        self::assertTrue($type->isBuiltIn());
        self::assertSame($this->tenant, $type->getTenant());
    }

    #[Test]
    public function createCustomTypeIsBlockedWhenFeatureFlagDisabled(): void
    {
        $service = $this->serviceWithCustomEnabled(false);

        $this->expectException(DisabledFeatureException::class);
        $service->create('shoes', ObjectKind::Custom, ['pl' => 'Buty', 'en' => 'Shoes']);
    }

    #[Test]
    public function createCustomTypeWorksWhenFeatureFlagEnabled(): void
    {
        $type = $this->serviceWithCustomEnabled(true)->create(
            'shoes',
            ObjectKind::Custom,
            ['pl' => 'Buty', 'en' => 'Shoes'],
        );

        self::assertSame(ObjectKind::Custom, $type->getKind());
        self::assertFalse($type->isBuiltIn());
    }

    #[Test]
    public function deleteBuiltInTypeIsBlocked(): void
    {
        $type = $this->serviceWithCustomEnabled(false)->create(
            'product',
            ObjectKind::Product,
            ['pl' => 'Produkt'],
            builtIn: true,
        );

        $this->expectException(BuiltInObjectTypeException::class);
        $this->serviceWithCustomEnabled(false)->delete($type);
    }

    #[Test]
    public function deleteNonBuiltInTypeWorks(): void
    {
        $service = $this->serviceWithCustomEnabled(true);
        $type = $service->create('shoes', ObjectKind::Custom, ['pl' => 'Buty']);
        $id = $type->getId();

        $service->delete($type);
        $this->em()->clear();

        self::assertNull($this->em()->find(ObjectType::class, $id));
    }

    #[Test]
    public function deleteWithLiveInstancesIsBlocked(): void
    {
        // Pre-check path: a custom ObjectType with a live `objects` row is
        // refused via the DBAL count guard, before any flush is attempted.
        $service = $this->serviceWithCustomEnabled(true);
        $type = $service->create('shoes', ObjectKind::Custom, ['pl' => 'Buty']);

        $object = new CatalogObject($type, 'SHOE-1');
        $this->em()->persist($object);
        $this->em()->flush();

        $this->expectException(ObjectTypeHasInstancesException::class);
        $service->delete($type);
    }

    #[Test]
    public function deleteCaughtForeignKeyViolationSurfacesAsHasInstances(): void
    {
        // AUD-072 (#1614) — the pre-delete count only looks at the `objects`
        // table. Another RESTRICT FK (here `import_profiles.target_object_type_id`)
        // is NOT counted, so the count guard passes (0 instances) but the
        // DELETE itself raises a Postgres FK violation — the exact race the
        // safety-net guards. The service must translate that DBAL exception
        // into ObjectTypeHasInstancesException (→ 409), not let it escape as a
        // raw 500. RED before the try/catch lands, GREEN after.
        $service = $this->serviceWithCustomEnabled(true);
        $type = $service->create('shoes', ObjectKind::Custom, ['pl' => 'Buty']);

        // ImportProfile references the type via target_object_type_id (RESTRICT)
        // but lives in a different table than `objects`, so countInstances()
        // returns 0 and the pre-check waves the delete through to the flush.
        $profile = new ImportProfile(Uuid::v7(), 'Cennik', $type);
        $this->em()->persist($profile);
        $this->em()->flush();

        $this->expectException(ObjectTypeHasInstancesException::class);
        $service->delete($type);
    }

    #[Test]
    public function assignAttributeCreatesJunction(): void
    {
        $service = $this->serviceWithCustomEnabled(false);
        $type = $service->create('product', ObjectKind::Product, ['pl' => 'Produkt'], builtIn: true);

        $junction = $service->assignAttribute($type, $this->someAttribute, required: true, sortOrder: 5);

        self::assertSame($type, $junction->getObjectType());
        self::assertSame($this->someAttribute, $junction->getAttribute());
        self::assertTrue($junction->isRequiredForCompleteness());
        self::assertSame(5, $junction->getSortOrder());
    }

    #[Test]
    public function assignAttributeIsIdempotentAndUpdatesExistingJunction(): void
    {
        $service = $this->serviceWithCustomEnabled(false);
        $type = $service->create('product', ObjectKind::Product, ['pl' => 'Produkt'], builtIn: true);

        $service->assignAttribute($type, $this->someAttribute, required: false, sortOrder: 0);
        $service->assignAttribute($type, $this->someAttribute, required: true, sortOrder: 10);

        $junctions = self::getContainer()
            ->get(ObjectTypeAttributeRepositoryInterface::class)
            ->findByObjectType($type);
        self::assertCount(1, $junctions);
        self::assertTrue($junctions[0]->isRequiredForCompleteness());
        self::assertSame(10, $junctions[0]->getSortOrder());
    }

    #[Test]
    public function updateUnlocksCapabilityFlagsOnBuiltInProduct(): void
    {
        // UX-03: hasVariants / isCategorizable / hasMultimedia drive *which
        // tabs* the operator sees, not the entity model itself. They must
        // be flippable on built-in rows too, otherwise Product is forever
        // stuck with its seed values.
        $service = $this->serviceWithCustomEnabled(false);
        $product = $service->create('product', ObjectKind::Product, ['pl' => 'Produkt'], builtIn: true);
        $product->setHasVariants(true);
        $product->setCategorizable(true);
        $product->setHasMultimedia(true);
        $this->em()->flush();

        $service->update($product, hasVariants: false);
        $service->update($product, isCategorizable: false);
        $service->update($product, hasMultimedia: false);

        self::assertFalse($product->hasVariants());
        self::assertFalse($product->isCategorizable());
        self::assertFalse($product->hasMultimedia());
    }

    #[Test]
    public function updateStillLocksStructuralFlagsOnBuiltInProduct(): void
    {
        // The structural flags (`hierarchical`, `abstract`,
        // `allowedParentTypeIds`, `completenessRules`) stay locked on
        // built-ins — they shape the entity model and the seeder owns
        // their values.
        $service = $this->serviceWithCustomEnabled(false);
        $product = $service->create('product', ObjectKind::Product, ['pl' => 'Produkt'], builtIn: true);

        $this->expectException(BuiltInObjectTypeException::class);
        $service->update($product, hierarchical: true);
    }

    #[Test]
    public function updateRejectsHasMultimediaTrueOnAsset(): void
    {
        // UX-03: Asset is itself the multimedia surface; promoting an
        // Asset ObjectType to host a Multimedia tab would be recursive.
        $service = $this->serviceWithCustomEnabled(false);
        $asset = $service->create('asset', ObjectKind::Asset, ['pl' => 'Zasób'], builtIn: true);

        $this->expectException(LogicException::class);
        $service->update($asset, hasMultimedia: true);
    }

    #[Test]
    public function unassignAttributeRemovesJunction(): void
    {
        $service = $this->serviceWithCustomEnabled(false);
        $type = $service->create('product', ObjectKind::Product, ['pl' => 'Produkt'], builtIn: true);
        $service->assignAttribute($type, $this->someAttribute);

        $service->unassignAttribute($type, $this->someAttribute);

        $junctions = self::getContainer()
            ->get(ObjectTypeAttributeRepositoryInterface::class)
            ->findByObjectType($type);
        self::assertSame([], $junctions);
    }

    private function serviceWithCustomEnabled(bool $flag): ObjectTypeService
    {
        return new ObjectTypeService(
            $this->em(),
            self::getContainer()->get(ObjectTypeAttributeRepositoryInterface::class),
            self::getContainer()->get(\App\Catalog\Domain\Repository\AttributeRepositoryInterface::class),
            self::getContainer()->get(\Doctrine\DBAL\Connection::class),
            $flag,
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
