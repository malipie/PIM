<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Application\Builder\ExportBuilder;
use App\Export\Application\Catalog\ExportBuilderCatalogValues;
use App\Export\Contracts\CatalogProductScope;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * CPDF-P2-02 — the Export-core adapter resolves catalog product values through
 * the existing {@see ExportBuilder}: a seeded product yields one attribute-code
 * keyed row carrying the seeded values, so the catalog generator can feed those
 * into template slots. Same set-based seeding as the Export/Feed benchmarks.
 */
final class ExportBuilderCatalogValuesTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function forScopeYieldsAttributeKeyedRowsForSeededProducts(): void
    {
        [, $typeId] = $this->seedObjects(2);

        // The adapter has no consumer until CPDF-P2-03, so the DI compiler
        // inlines/removes it — build it directly from the (retained) deps.
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $adapter = new ExportBuilderCatalogValues(
            $container->get(ExportBuilder::class),
            $container->get(CatalogObjectRepositoryInterface::class),
            $container->get(ObjectTypeRepositoryInterface::class),
            $container->get(FilterDslResolver::class),
            $em->getConnection(),
            $em,
            $container->get(TenantContext::class),
        );

        $scope = new CatalogProductScope($typeId, ['sku', 'name']);

        $rows = [];
        foreach ($adapter->forScope($scope) as $row) {
            $rows[] = $row;
        }

        self::assertCount(2, $rows, 'every seeded root product becomes one row');
        foreach ($rows as $row) {
            self::assertArrayHasKey('sku', $row);
            self::assertArrayHasKey('name', $row);
        }

        // Both attributes were seeded with the object code as value.
        $skus = array_column($rows, 'sku');
        $names = array_column($rows, 'name');
        sort($skus);
        sort($names);
        self::assertSame(['CPDF-1', 'CPDF-2'], $skus);
        self::assertSame($skus, $names, 'name mirrors sku (both seeded from the object code)');
    }

    /**
     * @return array{0: Tenant, 1: Uuid}
     */
    private function seedObjects(int $count): array
    {
        $em = $this->em();
        $tenant = new Tenant('cpdf', 'CPDF Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $type->markBuiltIn();
        $em->persist($type);
        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($type, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($type, $name, false, 2));
        $em->flush();

        $tenantId = $tenant->getId()->toRfc4122();
        $typeId = $type->getId();
        $conn = $em->getConnection();

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO objects (id, tenant_id, object_type_id, kind, code, enabled, status, completeness, attributes_indexed, created_at, updated_at, completeness_pct, sync_status_aggregate, version, schema_drift)
                SELECT gen_random_uuid(), :t, :ot, 'product', 'CPDF-'||g, true, 'published', '{}'::jsonb, '{}'::jsonb, now(), now(), 0, 'gray', 1, false
                FROM generate_series(1, :n) g
                SQL,
            ['t' => $tenantId, 'ot' => $typeId->toRfc4122(), 'n' => $count],
        );

        foreach ([$sku->getId()->toRfc4122(), $name->getId()->toRfc4122()] as $attrId) {
            $conn->executeStatement(
                <<<'SQL'
                    INSERT INTO object_values (id, tenant_id, object_id, attribute_id, value, provenance, provenance_meta)
                    SELECT gen_random_uuid(), :t, o.id, :a, jsonb_build_object('value', o.code), 'import', '{}'::jsonb
                    FROM objects o WHERE o.tenant_id = :t
                    SQL,
                ['t' => $tenantId, 'a' => $attrId],
            );
        }
        $em->clear();

        $reloaded = $em->getRepository(Tenant::class)->find($tenantId);
        \assert($reloaded instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($reloaded);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$reloaded, $typeId];
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
