<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\ObjectValueLocaleOverlay;
use App\Catalog\Application\Query\ObjectFactsReader;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P2-01 (#2331) — the grounding fact sheet against real Postgres:
 * locale overlay resolution, provenance stripping, sibling-locale
 * collection, missing-code reporting, and the read-only guarantee
 * (`attributes_indexed` byte-identical after a read).
 */
final class ObjectFactsReaderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function resolvesFactsPerLocaleWithSiblingReadingsAndMissingCodes(): void
    {
        $tenant = $this->createTenant();
        $object = $this->seedProduct($tenant);

        $facts = $this->port()->facts($object->getId(), ['material', 'color', 'size'], locale: 'pl');

        self::assertSame(['value' => 'aluminium'], $facts->values['material'] ?? null);
        self::assertSame(['option_code' => 'red'], $facts->values['color'] ?? null);
        self::assertSame(['size'], $facts->missingCodes);
        self::assertSame(['material', 'color'], $facts->presentCodes());
        // The EN row of material is a sibling reading (translation source).
        self::assertSame(['value' => 'aluminum'], $facts->siblingLocales['material']['en'] ?? null);
    }

    #[Test]
    public function localeOverlayWinsOverTheGlobalReading(): void
    {
        $tenant = $this->createTenant();
        $object = $this->seedProduct($tenant);

        $facts = $this->port()->facts($object->getId(), ['material'], locale: 'en');

        self::assertSame(['value' => 'aluminum'], $facts->values['material'] ?? null);
    }

    #[Test]
    public function provenanceKeysNeverLeakIntoFacts(): void
    {
        $tenant = $this->createTenant();
        $object = $this->seedProduct($tenant, withAgentProvenance: true);

        $facts = $this->port()->facts($object->getId(), ['material']);

        $material = $facts->values['material'] ?? [];
        self::assertArrayNotHasKey('provenance', $material);
        self::assertArrayNotHasKey('provenance_meta', $material);
    }

    #[Test]
    public function readingFactsLeavesAttributesIndexedUntouched(): void
    {
        $tenant = $this->createTenant();
        $object = $this->seedProduct($tenant);
        $conn = $this->em()->getConnection();
        $before = $conn->fetchOne('SELECT attributes_indexed::text FROM objects WHERE id = :id', ['id' => $object->getId()->toRfc4122()]);

        $this->port()->facts($object->getId(), ['material', 'color'], locale: 'en', channel: null);
        $this->em()->flush();

        $after = $conn->fetchOne('SELECT attributes_indexed::text FROM objects WHERE id = :id', ['id' => $object->getId()->toRfc4122()]);
        self::assertSame($before, $after, 'grounding must never write the cache (jsonb-schemas rule #1)');
    }

    #[Test]
    public function unknownObjectIdThrows(): void
    {
        $this->createTenant();

        $this->expectException(InvalidArgumentException::class);
        $this->port()->facts(Uuid::v7(), ['material']);
    }

    private function seedProduct(Tenant $tenant, bool $withAgentProvenance = false): CatalogObject
    {
        $em = $this->em();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);

        $material = new Attribute('material', ['en' => 'Material'], AttributeType::Text);
        $material->changeLocalizable(true);
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);
        $em->persist($material);
        $em->persist($color);

        $object = new CatalogObject($type, 'FACTS-1');
        $em->persist($object);

        $globalMaterial = new ObjectValue($object, $material, ['value' => 'aluminium']);
        if ($withAgentProvenance) {
            $globalMaterial->updateProvenanceMeta(['agent_run_id' => Uuid::v7()->toRfc4122()]);
        }
        $em->persist($globalMaterial);
        $em->persist(new ObjectValue($object, $material, ['value' => 'aluminum'], locale: 'en'));
        $em->persist(new ObjectValue($object, $color, ['option_code' => 'red']));
        $em->flush();

        return $object;
    }

    private function port(): ObjectFactsPort
    {
        // Constructed directly: the port's runtime consumer arrives with
        // the first content tool (AICG-P3-02), so until then the compiled
        // container inlines/removes the service (same caveat as the agent
        // run repository in AgentEntitiesTest).
        return new ObjectFactsReader(
            $this->em(),
            self::getContainer()->get(ObjectValueLocaleOverlay::class),
            self::getContainer()->get(ObjectValueRepositoryInterface::class),
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function createTenant(): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant('demo-facts', 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return $tenant;
    }
}
