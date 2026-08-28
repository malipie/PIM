<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Export\Application\Sync\SyncExportRunner;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Export\Domain\Repository\ExportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Shared\Infrastructure\Metrics\QueryDurationHistogram;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * IMP2-2.6 (#1482) + AUD-015/AUD-016 (#1632) — the streaming export path holds a
 * 50k-object export in constant memory for EVERY scope (All / Selected / Filter)
 * AND with include_variants on, instead of materialising the whole object graph.
 *
 * #1632 generalises the constant-memory keyset walk from the old All-masters fast
 * path to all scopes (the OOM finding), and batch-prefetches object_values /
 * relations / categories per page so the builder no longer issues one query per
 * object (the N+1 finding). Objects + values are seeded set-based (DB-side SQL,
 * negligible PHP memory) so the measured peak reflects {@see SyncExportRunner}
 * alone. Runs as a dedicated `import-benchmark` CI step.
 */
#[Group('import-benchmark')]
final class ExportMemoryBenchmarkTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const int THRESHOLD_BYTES = 256 * 1024 * 1024;
    private const int STREAMING_DELTA_BYTES = 128 * 1024 * 1024;

    #[Test]
    public function streamingExportOf5kStaysUnderMemoryBudget(): void
    {
        $tenant = $this->seedObjects(5_000);
        $this->assertExportStreamsWithinBudget($tenant, ExportTargetScope::All, false, 5_000);
    }

    #[Test]
    public function streamingExportOf50kStaysUnderMemoryBudget(): void
    {
        $tenant = $this->seedObjects(50_000);
        $this->assertExportStreamsWithinBudget($tenant, ExportTargetScope::All, false, 50_000);
    }

    /**
     * AUD-015 — the pre-#1632 OOM vector: scope All with variant fan-out used to
     * materialise the full master+variant graph. Now it streams: 50k masters ×
     * 2 variants = 150k emitted rows must still peak under 256 MiB.
     */
    #[Test]
    public function streamingExportOf50kWithVariantsStaysUnderMemoryBudget(): void
    {
        $tenant = $this->seedObjects(50_000, variantsPerMaster: 2);
        $this->assertExportStreamsWithinBudget($tenant, ExportTargetScope::All, true, 150_000);
    }

    /**
     * AUD-015 — Selected scope (a bounded id set) used to load every selected
     * object + its fanned-out children at once. With variants on it now streams
     * the id plan; 20k selected masters × 2 variants = 60k rows under budget.
     */
    #[Test]
    public function selectedScopeWithVariantsStreamsWithinBudget(): void
    {
        $tenant = $this->seedObjects(20_000, variantsPerMaster: 2);
        $selectedIds = $this->rootIds($tenant);

        $session = $this->newSession($tenant, ExportTargetScope::Selected, includeVariants: true, selectedObjectIds: $selectedIds);
        $this->runAndAssertWithinBudget($tenant, $session, 60_000);
    }

    /**
     * AUD-015 — Filter scope used to load the full DSL-matched set. A filter that
     * matches all 30k masters now streams the keyset id plan under budget.
     */
    #[Test]
    public function filterScopeStreamsWithinBudget(): void
    {
        $tenant = $this->seedObjects(30_000);
        // `enabled = TRUE` matches every seeded master (see seedObjects()); the
        // FilterDslResolver compiles this leaf to `co.enabled = true`.
        $session = $this->newSession(
            $tenant,
            ExportTargetScope::Filter,
            includeVariants: false,
            filterSnapshot: ['attr' => 'enabled', 'op' => '= TRUE'],
        );
        $this->runAndAssertWithinBudget($tenant, $session, 30_000);
    }

    /**
     * AUD-016 — N+1 elimination, measured end-to-end. The builder used to issue
     * one object_values SELECT (plus relations/categories) PER object; it now
     * batch-loads per CLEAR_INTERVAL-sized page. Query count must therefore grow
     * with the number of PAGES (O(N/200)), not with N. We seed two sizes one
     * page apart and assert the per-export query count stays within a small
     * constant band — proving the count does NOT scale linearly with rows.
     */
    #[Test]
    public function exportQueryCountScalesWithPagesNotRows(): void
    {
        $histogram = self::getContainer()->get(QueryDurationHistogram::class);
        self::assertInstanceOf(QueryDurationHistogram::class, $histogram);

        // 150 objects = 1 page (CLEAR_INTERVAL=200); 350 = 2 pages. Two distinct
        // tenants so both seeds coexist in the per-test database (ResetDatabase
        // only resets between tests, not within one).
        $onePage = $this->countExportQueries($histogram, 150, 'qc-one');
        $twoPages = $this->countExportQueries($histogram, 350, 'qc-two');

        // Per-page overhead is a small constant (values + columns + session
        // reload, etc.). The pre-#1632 path would add ~1 query PER object, so
        // 350 rows would cost ~200 more queries than 150. Batch prefetch keeps
        // the delta to a handful of per-page queries.
        self::assertLessThan(
            60,
            $twoPages - $onePage,
            \sprintf(
                'export query count must grow per-page, not per-row: 150 rows=%d queries, 350 rows=%d queries (delta %d)',
                $onePage,
                $twoPages,
                $twoPages - $onePage,
            ),
        );
        // Sanity: a 150-row export must be FAR below one-query-per-row.
        self::assertLessThan(150, $onePage, 'a single-page export must not issue a query per row (N+1)');
    }

    private function countExportQueries(QueryDurationHistogram $histogram, int $count, string $tenantCode): int
    {
        $tenant = $this->seedObjects($count, tenantCode: $tenantCode);
        $session = $this->newSession($tenant, ExportTargetScope::All, includeVariants: false);
        self::getContainer()->get(ExportSessionRepositoryInterface::class)->save($session);

        $target = tempnam(sys_get_temp_dir(), 'bench-export-qc-').'.csv';
        $runner = self::getContainer()->get(SyncExportRunner::class);

        $before = $histogram->count();
        $written = $runner->runToFile($session, $target);
        $delta = $histogram->count() - $before;
        @unlink($target);

        self::assertSame($count, $written, 'every seeded root object is exported');

        return $delta;
    }

    private function assertExportStreamsWithinBudget(
        Tenant $tenant,
        ExportTargetScope $scope,
        bool $includeVariants,
        int $expectedRows,
    ): void {
        $session = $this->newSession($tenant, $scope, $includeVariants);
        $this->runAndAssertWithinBudget($tenant, $session, $expectedRows);
    }

    private function runAndAssertWithinBudget(Tenant $tenant, ExportSession $session, int $expectedRows): void
    {
        self::getContainer()->get(ExportSessionRepositoryInterface::class)->save($session);

        $target = tempnam(sys_get_temp_dir(), 'bench-export-').'.csv';
        $runner = self::getContainer()->get(SyncExportRunner::class);

        \memory_reset_peak_usage();
        $before = \memory_get_usage(true);
        $written = $runner->runToFile($session, $target);
        $peak = \memory_get_peak_usage(true);
        @unlink($target);

        self::assertSame($expectedRows, $written, 'every expected row is exported');
        self::assertLessThan(
            self::THRESHOLD_BYTES,
            $peak,
            \sprintf('export of %d rows peaked at %0.1f MiB, must stay under 256 MiB', $expectedRows, $peak / 1024 / 1024),
        );
        // Streaming delta over the pre-run baseline must be a fraction of the
        // full set — proves we never materialise all N rows at once.
        self::assertLessThan(
            self::STREAMING_DELTA_BYTES,
            $peak - $before,
            \sprintf('streaming delta %0.1f MiB must stay bounded', ($peak - $before) / 1024 / 1024),
        );
    }

    /**
     * @param list<string>|null         $selectedObjectIds
     * @param array<string, mixed>|null $filterSnapshot
     */
    private function newSession(
        Tenant $tenant,
        ExportTargetScope $scope,
        bool $includeVariants,
        ?array $selectedObjectIds = null,
        ?array $filterSnapshot = null,
    ): ExportSession {
        $session = new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::ListContext,
            format: ExportFormat::Csv,
            targetScope: $scope,
            selectedColumns: ['sku', 'name', 'parent_sku'],
            filterSnapshot: $filterSnapshot,
            selectedObjectIds: $selectedObjectIds,
            includeVariants: $includeVariants,
        );
        $session->assignTenant($tenant);

        return $session;
    }

    /**
     * @return list<string>
     */
    private function rootIds(Tenant $tenant): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT id FROM objects WHERE tenant_id = :t AND parent_id IS NULL',
            ['t' => $tenant->getId()->toRfc4122()],
        );

        /** @var list<string> $ids */
        $ids = array_values(array_filter($rows, '\is_string'));

        return $ids;
    }

    private function seedObjects(int $count, int $variantsPerMaster = 0, string $tenantCode = 'bench'): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant($tenantCode, 'Bench Tenant '.$tenantCode);
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

        // Masters (root objects).
        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO objects (id, tenant_id, object_type_id, kind, code, enabled, status, completeness, attributes_indexed, created_at, updated_at, completeness_pct, sync_status_aggregate, version, schema_drift)
                SELECT gen_random_uuid(), :t, :ot, 'product', 'BENCH-'||g, true, 'published', '{}'::jsonb, '{}'::jsonb, now(), now(), 0, 'gray', 1, false
                FROM generate_series(1, :n) g
                SQL,
            ['t' => $tenantId, 'ot' => $typeId, 'n' => $count],
        );

        // Variants (children) — k per master, deterministic codes for ordering.
        if ($variantsPerMaster > 0) {
            $conn->executeStatement(
                <<<'SQL'
                    INSERT INTO objects (id, tenant_id, object_type_id, kind, code, enabled, status, completeness, attributes_indexed, created_at, updated_at, completeness_pct, sync_status_aggregate, version, schema_drift, parent_id)
                    SELECT gen_random_uuid(), :t, :ot, 'product', m.code||'-V'||v, true, 'published', '{}'::jsonb, '{}'::jsonb, now(), now(), 0, 'gray', 1, false, m.id
                    FROM objects m
                    CROSS JOIN generate_series(1, :k) v
                    WHERE m.tenant_id = :t AND m.parent_id IS NULL
                    SQL,
                ['t' => $tenantId, 'ot' => $typeId, 'k' => $variantsPerMaster],
            );
        }

        // One value per object for both attributes (so the builder's value
        // prefetch path is exercised, not just empty rows).
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
