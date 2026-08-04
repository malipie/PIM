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
use App\Export\Catalog\Application\Async\CatalogProgressPublisher;
use App\Export\Catalog\Application\Async\GenerateCatalogHandler;
use App\Export\Catalog\Application\CatalogPdfChromeFactory;
use App\Export\Catalog\Application\CatalogRenderService;
use App\Export\Catalog\Application\Generator\CatalogRegenerator;
use App\Export\Catalog\Application\HtmlValueSanitizer;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Enum\CatalogRunStatus;
use App\Export\Catalog\Domain\Enum\CatalogRunTrigger;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Message\RunCatalogMessage;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Catalog\Infrastructure\Delivery\FlysystemCatalogCacheStorage;
use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Support\InMemoryMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * CPDF-P3-01 — the async catalog generation worker end-to-end: a pending
 * CatalogRun drives the real render service → Regenerator → MinIO-backed cache,
 * lands the run in `done`, records the cache pointer on the profile and leaves a
 * `%PDF-` object at the tenant-scoped cache key. Same set-based seeding as
 * {@see CatalogRenderServiceTest}.
 */
final class GenerateCatalogHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function generatesCatalogToDoneWithCachedPdf(): void
    {
        [$tenant, $typeId] = $this->seedObjects(2, '<strong>Opis produktu</strong>');

        $profile = new CatalogProfile(
            'sheet-cat',
            'Sheet',
            CatalogTemplateKind::Sheet,
            $typeId,
            branding: ['color' => '#0ea5e9', 'company_name' => 'ACME'],
            fieldMappings: [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'description', 'source' => ['kind' => 'attribute', 'ref' => 'description']],
            ],
        );
        $profile->assignTenant($tenant);
        $this->profiles()->save($profile);

        $run = new CatalogRun($profile->getId(), CatalogRunTrigger::Manual);
        $run->assignTenant($tenant);
        $this->runs()->save($run);

        $runId = $run->getId();
        $cacheKey = sprintf(
            'catalogs/%s/%s.pdf',
            $tenant->getId()->toRfc4122(),
            $profile->getId()->toRfc4122(),
        );

        $handler = $this->handler();
        $handler(new RunCatalogMessage($runId, $tenant->getId()));

        // Re-scope after the render's EM clear before reading tenant-scoped rows
        // (the handler cleared the EM mid-run, detaching the seed's tenant).
        $this->rebind($this->reloadTenant($tenant->getId()->toRfc4122()));

        $reloadedRun = $this->runs()->findById($runId);
        self::assertInstanceOf(CatalogRun::class, $reloadedRun);
        self::assertSame(CatalogRunStatus::Done, $reloadedRun->getStatus(), $reloadedRun->getErrorMessage() ?? '');
        self::assertSame(2, $reloadedRun->getItemCount(), 'every seeded product becomes one sheet');
        self::assertNotNull($reloadedRun->getPageCount());
        self::assertGreaterThanOrEqual(1, $reloadedRun->getPageCount());

        $reloadedProfile = $this->profiles()->findById($profile->getId());
        self::assertInstanceOf(CatalogProfile::class, $reloadedProfile);
        self::assertSame($cacheKey, $reloadedProfile->getCachedFilePath());

        $operator = self::getContainer()->get('exports.storage');
        self::assertInstanceOf(FilesystemOperator::class, $operator);
        self::assertTrue($operator->fileExists($cacheKey), 'the generated PDF is cached under the tenant-scoped key');

        // Cleanup the cache artifact so repeat runs stay hermetic.
        $operator->delete($cacheKey);
    }

    private function handler(): GenerateCatalogHandler
    {
        $container = self::getContainer();

        // The handler's dependency chain reaches CatalogRenderService, whose
        // PdfRenderer alias the DI compiler inlines/removes (no other consumer),
        // so the handler is not resolvable from the test container — build the
        // chain directly (the CatalogRenderServiceTest pattern).
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

        $renderer = new CatalogRenderService(
            $values,
            new CatalogItemMapper(),
            new CatalogTemplateCatalog(),
            new HtmlValueSanitizer(),
            $container->get('twig'),
            new DompdfRenderer(),
            $container->get(AssetInliner::class),
            new CatalogPdfChromeFactory(
                new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
                new MockClock('2026-07-17T12:00:00+00:00'),
            ),
        );

        $regenerator = new CatalogRegenerator(
            $renderer,
            new FlysystemCatalogCacheStorage($container->get('exports.storage')),
            $this->profiles(),
            $this->runs(),
            $container->get(TenantContext::class),
        );

        return new GenerateCatalogHandler(
            $em,
            $em->getConnection(),
            $this->runs(),
            $this->profiles(),
            $regenerator,
            new CatalogProgressPublisher(new InMemoryMercureHub(), 'https://pim.localhost'),
            $container->get(TenantContext::class),
        );
    }

    private function profiles(): CatalogProfileRepositoryInterface
    {
        return self::getContainer()->get(CatalogProfileRepositoryInterface::class);
    }

    private function runs(): CatalogRunRepositoryInterface
    {
        return self::getContainer()->get(CatalogRunRepositoryInterface::class);
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

        $reloaded = $this->rebind($this->reloadTenant($tenantId));

        return [$reloaded, $typeId];
    }

    private function reloadTenant(string $tenantId): Tenant
    {
        $reloaded = $this->em()->getRepository(Tenant::class)->find($tenantId);
        \assert($reloaded instanceof Tenant);

        return $reloaded;
    }

    private function rebind(Tenant $tenant): Tenant
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return $tenant;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
