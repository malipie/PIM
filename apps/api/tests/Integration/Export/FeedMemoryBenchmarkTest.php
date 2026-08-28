<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Export\Feed\Application\Generator\FeedGenerator;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const STDERR;

/**
 * XMLF-P6-03 — feed generation of a 50k-SKU catalog stays streaming (plan
 * §6.12, CLAUDE.md §3.10): peak memory under the 256 MiB worker ceiling,
 * bounded streaming delta, a FLAT memory profile (no growth proportional to
 * the item count — the DOM-build failure mode), and a stable Identity Map
 * (the per-chunk EntityManager::clear() in ExportBuilderFeedValues works).
 *
 * Same contract and thresholds as {@see ExportMemoryBenchmarkTest}; objects
 * and values are seeded set-based (DB-side SQL, negligible PHP memory) so the
 * measured peak reflects {@see FeedGenerator} alone. Runs in the dedicated
 * `import-benchmark` CI step, not the main shard matrix.
 *
 * The Prometheus runtime guard-rail for the same budget already exists:
 * `frankenphp_worker_memory_bytes` on /api/metrics + the >256 MiB alert in
 * docker/prometheus/alerts.yml (with a 192 MiB trend warning).
 */
#[Group('import-benchmark')]
final class FeedMemoryBenchmarkTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const int THRESHOLD_BYTES = 256 * 1024 * 1024;
    private const int STREAMING_DELTA_BYTES = 128 * 1024 * 1024;
    /** §6.12 targets ~30s for 50k; the hard CI ceiling leaves headroom for
     * shared runners — the real duration is logged for trend-watching. */
    private const int HARD_TIME_CEILING_SECONDS = 120;
    /** Between the 25%-progress sample and the end the resident memory of a
     * streaming generator may only move by buffers, never by the item count. */
    private const int FLATNESS_BAND_BYTES = 32 * 1024 * 1024;
    /** With clear() every 200 rows the Identity Map holds one page + statics. */
    private const int UOW_CEILING_ENTITIES = 5_000;

    #[Test]
    public function feedGenerationOf50kStaysFlatAndUnderMemoryBudget(): void
    {
        $tenant = $this->seedObjects(50_000);
        $profile = $this->persistFeed($tenant, 'bench_custom', $this->customDescriptor(), [
            ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
        ]);

        $this->generateAndAssertBudget($profile, 50_000, sampleFlatness: true);
    }

    /**
     * Worst-case node structure — the Ceneo shape: five attribute nodes on
     * `<o>`, a CDATA description, a wrapped main image and a REPEATABLE
     * wrapped gallery node. More XML nodes per item than any predef; the
     * writer must still stream it within the same budget.
     */
    #[Test]
    public function ceneoShapedFeedOf50kStaysUnderMemoryBudget(): void
    {
        $tenant = $this->seedObjects(50_000);
        $profile = $this->persistFeed($tenant, 'bench_ceneo', $this->ceneoShapeDescriptor(), [
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'url', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ['slot' => 'price', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'stock', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ['slot' => 'desc', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ['slot' => 'imgs.main', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'imgs.i', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
        ]);

        $this->generateAndAssertBudget($profile, 50_000, sampleFlatness: false);
    }

    private function generateAndAssertBudget(FeedProfile $profile, int $expectedItems, bool $sampleFlatness): void
    {
        $generator = self::getContainer()->get(FeedGenerator::class);
        $em = $this->em();

        $target = tempnam(sys_get_temp_dir(), 'bench-feed-').'.xml';

        /** @var array<int, array{processed: int, memory: int, uow: int}> $samples */
        $samples = [];
        $onChunk = static function (int $processed) use (&$samples, $em): void {
            // Sample every 25 chunks (5k rows) — enough resolution to prove
            // flatness without distorting the run.
            if (0 === $processed % 5_000) {
                $samples[] = [
                    'processed' => $processed,
                    'memory' => memory_get_usage(true),
                    'uow' => $em->getUnitOfWork()->size(),
                ];
            }
        };

        \memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $startedAt = microtime(true);
        $run = $generator->generate($profile, $target, FeedRunTrigger::Manual, null, $onChunk);
        $elapsed = microtime(true) - $startedAt;
        $peak = memory_get_peak_usage(true);
        $bytes = filesize($target);
        @unlink($target);

        self::assertSame($expectedItems, $run->getItemCount(), 'every seeded object becomes a feed item');
        self::assertIsInt($bytes);
        self::assertGreaterThan(1024 * 1024, $bytes, 'a 50k feed is a multi-MB document');

        self::assertLessThan(
            self::THRESHOLD_BYTES,
            $peak,
            \sprintf('feed generation of %d items peaked at %0.1f MiB, must stay under 256 MiB', $expectedItems, $peak / 1024 / 1024),
        );
        self::assertLessThan(
            self::STREAMING_DELTA_BYTES,
            $peak - $before,
            \sprintf('streaming delta %0.1f MiB must stay bounded', ($peak - $before) / 1024 / 1024),
        );

        // §6.12 pace: log the real duration, hard-fail only above the CI-safe
        // ceiling so a busy shared runner does not flake the build.
        fwrite(STDERR, \sprintf(
            "\n[feed-benchmark] %s: %d items, %0.1f MiB file, peak %0.1f MiB (delta %0.1f), %0.1fs\n",
            $profile->getCode(),
            $expectedItems,
            $bytes / 1024 / 1024,
            $peak / 1024 / 1024,
            ($peak - $before) / 1024 / 1024,
            $elapsed,
        ));
        self::assertLessThan(
            self::HARD_TIME_CEILING_SECONDS,
            $elapsed,
            \sprintf('50k generation took %0.1fs — the §6.12 target is ~30s, the CI ceiling %ds', $elapsed, self::HARD_TIME_CEILING_SECONDS),
        );

        if (!$sampleFlatness) {
            return;
        }

        // Flat RAM: between 25% progress and the finish line the resident
        // memory may move by buffer-sized amounts only. A DOM build (or any
        // materialise-all path) would grow linearly with the item count.
        self::assertGreaterThanOrEqual(4, \count($samples), 'sampling must cover the run');
        $quarter = $samples[(int) floor(\count($samples) / 4)];
        $last = $samples[\count($samples) - 1];
        self::assertLessThan(
            self::FLATNESS_BAND_BYTES,
            $last['memory'] - $quarter['memory'],
            \sprintf(
                'memory must stay flat after warm-up: %0.1f MiB at %d rows vs %0.1f MiB at %d rows',
                $quarter['memory'] / 1024 / 1024,
                $quarter['processed'],
                $last['memory'] / 1024 / 1024,
                $last['processed'],
            ),
        );

        // Identity Map stability — the per-chunk clear() keeps the managed
        // entity count at one page's worth; unbounded growth means a missing
        // clear() and an eventual worker OOM.
        $maxUow = max([0, ...array_column($samples, 'uow')]);
        self::assertLessThan(
            self::UOW_CEILING_ENTITIES,
            $maxUow,
            \sprintf('Identity Map must not accumulate across chunks (max %d managed entities)', $maxUow),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function customDescriptor(): array
    {
        return [
            'root' => ['element' => 'products'],
            'item' => [
                'element' => 'product',
                'slots' => [
                    ['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                    ['slot' => 'name', 'node' => 'element', 'fmt' => 'text'],
                ],
            ],
        ];
    }

    /**
     * The Ceneo structure without the channel-category slot (fmt=category
     * needs a mapped channel tree — P3-03 covers that path; here every item
     * must pass so the benchmark measures the writer, not the validator).
     *
     * @return array<string, mixed>
     */
    private function ceneoShapeDescriptor(): array
    {
        return [
            'root' => ['element' => 'offers', 'attributes' => ['version' => '1']],
            'item' => [
                'element' => 'o',
                'slots' => [
                    ['target' => 'id', 'node' => 'attribute', 'parent' => 'o', 'element' => 'id', 'required' => true, 'fmt' => 'text'],
                    ['target' => 'url', 'node' => 'attribute', 'parent' => 'o', 'element' => 'url', 'fmt' => 'text'],
                    ['target' => 'price', 'node' => 'attribute', 'parent' => 'o', 'element' => 'price', 'fmt' => 'text'],
                    ['target' => 'stock', 'node' => 'attribute', 'parent' => 'o', 'element' => 'stock', 'fmt' => 'text'],
                    ['target' => 'name', 'node' => 'element', 'element' => 'name', 'required' => true, 'maxLength' => 200, 'fmt' => 'text'],
                    ['target' => 'desc', 'node' => 'element', 'element' => 'desc', 'html' => 'cdata', 'fmt' => 'text'],
                    ['target' => 'imgs.main', 'node' => 'element', 'element' => 'main', 'wrapIn' => 'imgs', 'fmt' => 'text'],
                    ['target' => 'imgs.i', 'node' => 'repeatable', 'element' => 'i', 'wrapIn' => 'imgs', 'fmt' => 'text'],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param array<string, mixed>       $descriptor
     */
    private function persistFeed(Tenant $tenant, string $code, array $descriptor, array $mappings): FeedProfile
    {
        $em = $this->em();
        $type = $em->getRepository(ObjectType::class)->findOneBy(['code' => 'product']);
        \assert($type instanceof ObjectType);

        $profile = new FeedProfile(
            code: $code,
            name: 'Benchmark feed '.$code,
            templateKind: FeedTemplateKind::Custom,
            objectTypeId: $type->getId(),
            descriptor: $descriptor,
            fieldMappings: $mappings,
            locale: 'pl',
        );
        $profile->assignTenant($tenant);
        $em->persist($profile);
        $em->flush();

        return $profile;
    }

    private function seedObjects(int $count): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant('bench', 'Bench Tenant');
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
        $typeId = $type->getId()->toRfc4122();
        $conn = $em->getConnection();

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO objects (id, tenant_id, object_type_id, kind, code, enabled, status, completeness, attributes_indexed, created_at, updated_at, completeness_pct, sync_status_aggregate, version, schema_drift)
                SELECT gen_random_uuid(), :t, :ot, 'product', 'BENCH-'||g, true, 'published', '{}'::jsonb, '{}'::jsonb, now(), now(), 0, 'gray', 1, false
                FROM generate_series(1, :n) g
                SQL,
            ['t' => $tenantId, 'ot' => $typeId, 'n' => $count],
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

        return $reloaded;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
