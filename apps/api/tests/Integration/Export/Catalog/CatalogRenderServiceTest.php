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
use App\Export\Catalog\Application\CatalogRenderResult;
use App\Export\Catalog\Application\CatalogRenderService;
use App\Export\Catalog\Application\HtmlValueSanitizer;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
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
 * CPDF-P2-03 — the sheet archetype end-to-end: a CatalogProfile renders through
 * the real value source (ExportBuilder), mapper, sanitiser, Twig archetype and
 * the Dompdf PdfRenderer to a `%PDF-` binary on disk. Same set-based seeding as
 * {@see ExportBuilderCatalogValuesTest}.
 */
final class CatalogRenderServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function rendersSheetCatalogToPdf(): void
    {
        [, $typeId] = $this->seedObjects(2, '<strong>Opis produktu</strong>');

        $service = $this->service();
        $profile = $this->profile($typeId);

        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);
        $result = $service->render($profile, $target);

        self::assertInstanceOf(CatalogRenderResult::class, $result);
        self::assertSame(2, $result->itemCount, 'every seeded product becomes one sheet');
        self::assertGreaterThan(0, $result->byteSize);
        self::assertGreaterThanOrEqual(1, $result->pageCount);

        $pdf = (string) file_get_contents($target);
        self::assertStringStartsWith('%PDF-', $pdf, 'the render produced a valid PDF file');

        @unlink($target);
    }

    #[Test]
    public function renderSurvivesMaliciousDescriptionValue(): void
    {
        [, $typeId] = $this->seedObjects(1, '<script>alert(1)</script>');

        $service = $this->service();
        $profile = $this->profile($typeId);

        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);
        // Garbage data must not break the render — the sanitiser neutralises it.
        $service->render($profile, $target);

        $pdf = (string) file_get_contents($target);
        self::assertStringStartsWith('%PDF-', $pdf);

        @unlink($target);
    }

    private function service(): CatalogRenderService
    {
        // No consumer wires CatalogRenderService yet, so the DI compiler may
        // inline/remove it — build it directly from its (retained) deps, the
        // ExportBuilderCatalogValuesTest pattern. Twig is fetched by the 'twig'
        // service id used across the Catalog tests.
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $values = new ExportBuilderCatalogValues(
            $container->get(ExportBuilder::class),
            $container->get(CatalogObjectRepositoryInterface::class),
            $container->get(ObjectTypeRepositoryInterface::class),
            $container->get(FilterDslResolver::class),
            $em->getConnection(),
            $em,
            $container->get(TenantContext::class),
        );

        $twig = $container->get('twig');

        return new CatalogRenderService(
            $values,
            new CatalogItemMapper(),
            new CatalogTemplateCatalog(),
            new HtmlValueSanitizer(),
            $twig,
            // PdfRenderer alias has no consumer yet (DI removes it) — the default
            // in-process Dompdf adapter is constructor-less, use it directly.
            new DompdfRenderer(),
        );
    }

    private function profile(Uuid $objectTypeId): CatalogProfile
    {
        return new CatalogProfile(
            'sheet-cat',
            'Sheet',
            CatalogTemplateKind::Sheet,
            $objectTypeId,
            branding: ['color' => '#0ea5e9', 'company_name' => 'ACME'],
            fieldMappings: [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'description', 'source' => ['kind' => 'attribute', 'ref' => 'description']],
            ],
        );
    }

    /**
     * @return array{0: Tenant, 1: Uuid}
     */
    private function seedObjects(int $count, string $descriptionValue): array
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
        $description = new Attribute('description', ['en' => 'Description'], AttributeType::Wysiwyg);
        $em->persist($sku);
        $em->persist($name);
        $em->persist($description);
        $em->persist(new ObjectTypeAttribute($type, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($type, $name, false, 2));
        $em->persist(new ObjectTypeAttribute($type, $description, false, 3));
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

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO object_values (id, tenant_id, object_id, attribute_id, value, provenance, provenance_meta)
                SELECT gen_random_uuid(), :t, o.id, :a, jsonb_build_object('value', :d::text), 'import', '{}'::jsonb
                FROM objects o WHERE o.tenant_id = :t
                SQL,
            ['t' => $tenantId, 'a' => $description->getId()->toRfc4122(), 'd' => $descriptionValue],
        );
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
