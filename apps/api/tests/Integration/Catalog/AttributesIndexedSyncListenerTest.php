<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\BulkContext;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AttributesIndexedSyncListenerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;
    private ObjectType $productType;
    private Attribute $color;
    private Attribute $name;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $this->tenant = new Tenant('demo', 'Demo');
        $em->persist($this->tenant);
        $em->flush();
        $this->tenantContext()->set($this->tenant);

        $this->productType = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $this->productType->updateCompletenessRules(['required' => ['name', 'color']]);
        $em->persist($this->productType);

        $this->name = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);
        $this->color = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);
        $em->persist($this->name);
        $em->persist($this->color);

        $em->flush();
    }

    #[Test]
    public function persistingObjectValueUpdatesAttributesIndexedAndCompleteness(): void
    {
        $em = $this->em();
        $object = new CatalogObject($this->productType, 'SKU-1');
        $em->persist($object);
        $em->flush();

        $colorValue = new ObjectValue($object, $this->color, ['option_code' => 'red']);
        $em->persist($colorValue);
        $em->flush();

        $em->clear();
        $reloaded = $this->repository()->findByCode('SKU-1', ObjectKind::Product, $this->tenant);
        self::assertNotNull($reloaded);
        // AGENT-P6-05 (#1978) — the projection slot carries provenance.
        self::assertSame(['provenance' => 'manual', 'option_code' => 'red'], $reloaded->getAttributesIndexed()['color']);
        // 1 of 2 required fields present → 50%.
        self::assertSame(50, $reloaded->getCompleteness()['global']);
    }

    #[Test]
    public function bulkContextBypassesTheListener(): void
    {
        self::getContainer()->get(BulkContext::class)->setBulk(true);

        $em = $this->em();
        $object = new CatalogObject($this->productType, 'SKU-2');
        $em->persist($object);
        $em->flush();

        $colorValue = new ObjectValue($object, $this->color, ['option_code' => 'blue']);
        $em->persist($colorValue);
        $em->flush();

        $em->clear();
        $reloaded = $this->repository()->findByCode('SKU-2', ObjectKind::Product, $this->tenant);
        self::assertNotNull($reloaded);
        // Listener bypassed → cache stays empty + completeness untouched.
        self::assertSame([], $reloaded->getAttributesIndexed());
        self::assertSame([], $reloaded->getCompleteness());

        // Reset for downstream tests in the same kernel boot.
        self::getContainer()->get(BulkContext::class)->setBulk(false);
    }

    #[Test]
    public function perLocaleRowsStayOutOfTheGlobalCache(): void
    {
        $em = $this->em();
        $object = new CatalogObject($this->productType, 'SKU-LOC');
        $em->persist($object);
        $em->flush();

        // Global (locale=null) reading.
        $em->persist(new ObjectValue($object, $this->name, ['value' => 'Nazwa PL']));
        $em->flush();
        // Per-locale reading must NOT leak into attributes_indexed (#1148):
        // the cache stays global so lists + Meilisearch are deterministic.
        $em->persist(new ObjectValue($object, $this->name, ['value' => 'Name EN'], locale: 'en'));
        $em->flush();

        $em->clear();
        $reloaded = $this->repository()->findByCode('SKU-LOC', ObjectKind::Product, $this->tenant);
        self::assertNotNull($reloaded);
        self::assertSame(['value' => 'Nazwa PL', 'provenance' => 'manual'], $reloaded->getAttributesIndexed()['name']);
    }

    #[Test]
    public function completenessReportsHundredWhenRulesAreEmpty(): void
    {
        $em = $this->em();
        $emptyRulesType = new ObjectType('asset', ObjectKind::Asset, ['pl' => 'Zasób']);
        $em->persist($emptyRulesType);
        $em->flush();

        $object = new CatalogObject($emptyRulesType, 'IMG-1');
        $em->persist($object);
        $em->flush();

        $value = new ObjectValue($object, $this->name, ['value' => 'hello']);
        $em->persist($value);
        $em->flush();

        $em->clear();
        $reloaded = $this->repository()->findByCode('IMG-1', ObjectKind::Asset, $this->tenant);
        self::assertNotNull($reloaded);
        self::assertSame(100, $reloaded->getCompleteness()['global']);
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

    private function repository(): CatalogObjectRepositoryInterface
    {
        return self::getContainer()->get(CatalogObjectRepositoryInterface::class);
    }
}
