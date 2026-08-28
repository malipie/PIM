<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Integration;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Integration\InboundRecordWriter;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * APIC-P3-04 — the inbound write seam upserts a remote record into the catalog
 * through BatchValueWriter (Provenance::Integration), resolving by match key and
 * creating when absent. Verifies the create/update/skip lifecycle + provenance
 * end-to-end against a real Postgres.
 */
final class CatalogInboundRecordWriterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;
    private ObjectType $productType;

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
        $em->persist($this->productType);
        $em->persist(new Attribute('sku', ['pl' => 'SKU'], AttributeType::Text));
        $em->persist(new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text));
        $em->flush();
    }

    #[Test]
    public function createsThenUpdatesThenSkips(): void
    {
        $writer = $this->writer();
        $objectTypeId = $this->productType->getId();

        $created = $writer->upsert($objectTypeId, 'sku', 'A-1', ['name' => 'Widget']);
        $this->em()->flush();
        self::assertSame('created', $created->action);
        self::assertNotNull($created->objectId);

        $object = $this->repository()->findByCode('A-1', ObjectKind::Product, $this->tenant);
        self::assertNotNull($object);
        self::assertArrayHasKey('name', $object->getAttributesIndexed());

        // Every written value carries Integration provenance.
        $values = $this->em()->getRepository(ObjectValue::class)->findBy(['object' => $object]);
        self::assertNotEmpty($values);
        foreach ($values as $value) {
            self::assertSame(Provenance::Integration, $value->getProvenance());
        }

        $updated = $writer->upsert($objectTypeId, 'sku', 'A-1', ['name' => 'Widget v2']);
        $this->em()->flush();
        self::assertSame('updated', $updated->action);
        self::assertSame($created->objectId, $updated->objectId);

        $skipped = $writer->upsert($objectTypeId, 'sku', 'A-1', ['name' => 'Widget v2']);
        $this->em()->flush();
        self::assertSame('skipped', $skipped->action);
    }

    #[Test]
    public function failsOnUnknownMatchAttribute(): void
    {
        $result = $this->writer()->upsert($this->productType->getId(), 'nope', 'A-1', ['name' => 'X']);

        self::assertSame('failed', $result->action);
        self::assertNotEmpty($result->issues);
    }

    #[Test]
    public function failsOnEmptyMatchValue(): void
    {
        $result = $this->writer()->upsert($this->productType->getId(), 'sku', '   ', ['name' => 'X']);

        self::assertSame('failed', $result->action);
    }

    private function writer(): InboundRecordWriter
    {
        return self::getContainer()->get(InboundRecordWriter::class);
    }

    private function repository(): CatalogObjectRepositoryInterface
    {
        return self::getContainer()->get(CatalogObjectRepositoryInterface::class);
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
