<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use App\Asset\Contracts\Service\AssetInliner;
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
use App\Export\Catalog\Application\CatalogPdfChromeFactory;
use App\Export\Catalog\Application\CatalogRenderResult;
use App\Export\Catalog\Application\CatalogRenderService;
use App\Export\Catalog\Application\CatalogTooLargeException;
use App\Export\Catalog\Application\HtmlValueSanitizer;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use App\Export\Contracts\PdfRenderer;
use App\Export\Contracts\PdfRenderOptions;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
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
    public function rendersPricelistCatalogToMultipagePdf(): void
    {
        // ~60 rows do not fit one A4 page — the table must paginate (CPDF-P6-01).
        [, $typeId] = $this->seedObjects(60, 'plain');

        $service = $this->service();
        $profile = new CatalogProfile(
            'pricelist-cat',
            'Pricelist',
            CatalogTemplateKind::Pricelist,
            $typeId,
            branding: ['color' => '#0ea5e9', 'company_name' => 'ACME'],
            fieldMappings: [
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                // The seed schema has no price/availability attributes — static
                // sources exercise the same mapper path.
                ['slot' => 'price', 'source' => ['kind' => 'static', 'value' => '199,00 zł']],
                ['slot' => 'availability', 'source' => ['kind' => 'static', 'value' => 'in stock']],
            ],
        );

        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);
        $result = $service->render($profile, $target);

        self::assertSame(60, $result->itemCount, 'every seeded product becomes one table row');
        self::assertGreaterThan(1, $result->pageCount, 'a 60-row price table spans multiple pages');

        $pdf = (string) file_get_contents($target);
        self::assertStringStartsWith('%PDF-', $pdf, 'the render produced a valid PDF file');

        @unlink($target);
    }

    #[Test]
    public function rendersGridCatalogWithCoverAndTocToPdf(): void
    {
        // Cover page + TOC page + the card grid — 30 products must span
        // at least three pages (CPDF-P6-02).
        [, $typeId] = $this->seedObjects(30, 'plain');

        $service = $this->service();
        $profile = new CatalogProfile(
            'grid-cat',
            'Grid catalog',
            CatalogTemplateKind::Grid,
            $typeId,
            branding: ['color' => '#0ea5e9', 'company_name' => 'ACME'],
            fieldMappings: [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'price', 'source' => ['kind' => 'static', 'value' => '99,00 zł']],
            ],
        );

        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);
        $result = $service->render($profile, $target);

        self::assertSame(30, $result->itemCount, 'every seeded product becomes one grid card');
        self::assertGreaterThanOrEqual(3, $result->pageCount, 'cover + TOC + grid pages');

        $pdf = (string) file_get_contents($target);
        self::assertStringStartsWith('%PDF-', $pdf, 'the render produced a valid PDF file');

        @unlink($target);
    }

    #[Test]
    public function capRaisesActionableErrorOnInMemoryRenderer(): void
    {
        // Decision A (CPDF-P6-04): Dompdf answers false to
        // supportsLargeDocuments(), so exceeding the cap stops the render with
        // an actionable message instead of OOM-killing the worker.
        [, $typeId] = $this->seedObjects(12, 'plain');

        $service = $this->service(maxInMemoryItems: 10);
        $profile = $this->profile($typeId);

        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);

        try {
            $service->render($profile, $target);
            self::fail('expected CatalogTooLargeException');
        } catch (CatalogTooLargeException $error) {
            self::assertStringContainsString('10-product limit', $error->getMessage());
            self::assertStringContainsString('Gotenberg', $error->getMessage());
        } finally {
            @unlink($target);
        }
    }

    #[Test]
    public function capDoesNotApplyToLargeDocumentRenderers(): void
    {
        [, $typeId] = $this->seedObjects(12, 'plain');

        // A Gotenberg-class renderer (supportsLargeDocuments() === true)
        // renders uncapped — the same 12 products pass a cap of 10.
        $sidecarLike = new class implements PdfRenderer {
            public function supportsLargeDocuments(): bool
            {
                return true;
            }

            public function render(string $html, PdfRenderOptions $options): string
            {
                return '%PDF-fake';
            }
        };

        $service = $this->service($sidecarLike, maxInMemoryItems: 10);
        $profile = $this->profile($typeId);

        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);
        $result = $service->render($profile, $target);

        self::assertSame(12, $result->itemCount, 'the cap only guards in-memory renderers');

        @unlink($target);
    }

    #[Test]
    public function sheetUnderTheHqCapPrefersTheMediumImageVariant(): void
    {
        // #2608 — sheet pages are carried by one large image, so under the HQ
        // cap the render asks the inliner for the medium (800px) variant.
        [, $typeId] = $this->seedObjects(2, 'plain');

        $recorder = $this->recordingInliner();
        $service = $this->service(inliner: $recorder);
        $service->render($this->profileWithImage($typeId), $this->target());

        self::assertSame(['medium', 'medium'], $recorder->variants);
    }

    #[Test]
    public function sheetOverTheHqCapFallsBackToTheMemoryFirstOrder(): void
    {
        [, $typeId] = $this->seedObjects(2, 'plain');

        $recorder = $this->recordingInliner();
        $service = $this->service(inliner: $recorder, hqImageMaxItems: 1);
        $service->render($this->profileWithImage($typeId), $this->target());

        // No preferred variant — the inliner keeps its thumb-first order.
        self::assertSame([null, null], $recorder->variants);
    }

    #[Test]
    public function unresolvableAssetReferenceStillRendersAPdf(): void
    {
        // #2608 — an image reference the inliner cannot resolve collapses to
        // the template placeholder instead of a broken <img src="<uuid>">.
        [, $typeId] = $this->seedObjects(1, 'plain');

        $nullInliner = new class implements AssetInliner {
            public function toDataUri(string $reference, ?string $preferredVariant = null): ?string
            {
                return null;
            }
        };
        $service = $this->service(inliner: $nullInliner);
        $target = $this->target();
        $service->render($this->profileWithImage($typeId), $target);

        self::assertStringStartsWith('%PDF-', (string) file_get_contents($target));
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

    private function service(
        ?PdfRenderer $renderer = null,
        int $maxInMemoryItems = 500,
        ?AssetInliner $inliner = null,
        int $hqImageMaxItems = 48,
    ): CatalogRenderService {
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
            $renderer ?? new DompdfRenderer(),
            $inliner ?? $container->get(AssetInliner::class),
            new CatalogPdfChromeFactory(
                new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
                new MockClock('2026-07-17T12:00:00+00:00'),
            ),
            $maxInMemoryItems,
            $hqImageMaxItems,
        );
    }

    /**
     * @return AssetInliner&object{variants: list<string|null>}
     */
    private function recordingInliner(): AssetInliner
    {
        return new class implements AssetInliner {
            /** @var list<string|null> */
            public array $variants = [];

            public function toDataUri(string $reference, ?string $preferredVariant = null): string
            {
                $this->variants[] = $preferredVariant;

                return 'data:image/png;base64,iVBORw0KGgo=';
            }
        };
    }

    private function profileWithImage(Uuid $objectTypeId): CatalogProfile
    {
        return new CatalogProfile(
            'sheet-img-cat',
            'Sheet with images',
            CatalogTemplateKind::Sheet,
            $objectTypeId,
            branding: ['color' => '#0ea5e9', 'company_name' => 'ACME'],
            fieldMappings: [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                // The seed schema has no media attribute — a static asset-style
                // reference exercises the same inliner path.
                ['slot' => 'image', 'source' => ['kind' => 'static', 'value' => '0198aaaa-bbbb-7ccc-8ddd-eeeeffff0000']],
            ],
        );
    }

    private function target(): string
    {
        $target = tempnam(sys_get_temp_dir(), 'cpdf-');
        self::assertNotFalse($target);

        return $target;
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
