<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Shared\Infrastructure\Metrics\QueryDurationHistogram;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2231 — the drift reconcile must scale with pages, not with rows.
 *
 * On the 50k production catalogue `pim:catalog:detect-attributes-drift
 * --reconcile` ran for 110 minutes without finishing, sitting at 213 MiB
 * against the 256 MiB ceiling, and printed nothing while it did. Two causes,
 * both on the read path: `toIterable()` opens no server-side cursor, so libpq
 * buffers the entire result set client-side before hydration begins — memory
 * `EntityManager::clear()` cannot reclaim — and the canonical reading was
 * fetched one object at a time, roughly 1.7M queries for the run.
 *
 * What this asserts, and why in this shape: at a size a test can build, a
 * megabyte threshold separates nothing — the broken version fits comfortably
 * under 256 MiB too. **Query count** is the honest measure, because it is what
 * changed in kind: round trips now grow with pages (objects / 200) rather than
 * with rows. That is the difference between a 50k reconcile finishing and not.
 *
 * The memory assertion rides along as a floor, not as the point: it fails only
 * if something reintroduces an unbounded accumulation.
 */
#[Group('import-benchmark')]
final class DriftScanMemoryBenchmarkTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** Ten pages of the command's 200-object page size. */
    private const int OBJECTS = 2000;

    /** Global values per object, all of them drifting so reconcile does real work. */
    private const int VALUES_PER_OBJECT = 3;

    private const int THRESHOLD_BYTES = 256 * 1024 * 1024;

    #[Test]
    public function reconcileQueriesScaleWithPagesNotRows(): void
    {
        $em = $this->em();
        $tenant = new Tenant('drift-bench', 'Drift Bench Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $this->seedCatalogue($tenant);

        // The counter production already uses: QueryTimingMiddleware wraps
        // every statement and feeds this histogram, so no test-only
        // instrumentation stands between the measurement and the runtime.
        $histogram = self::getContainer()->get(QueryDurationHistogram::class);
        $queriesBefore = $histogram->count();

        \memory_reset_peak_usage();
        $before = \memory_get_usage(true);

        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);
        $tester = new CommandTester(
            new Application($kernel)->find('pim:catalog:detect-attributes-drift'),
        );
        $tester->execute(['--reconcile' => true, '--tenant' => 'drift-bench']);

        $peak = \memory_get_peak_usage(true);
        $queries = $histogram->count() - $queriesBefore;

        $output = $tester->getDisplay();
        self::assertStringContainsString(
            (string) self::OBJECTS,
            $output,
            'precondition: the scan actually walked the seeded catalogue',
        );

        // Round trips must track pages. Per page the command issues one object
        // query and one batched value query, plus a bounded amount of write and
        // transaction traffic for the objects it repairs. The pre-#2231 shape
        // issued one value query PER OBJECT — an order of magnitude more, and
        // the reason a 50k run never finished.
        $pages = (int) ceil(self::OBJECTS / 200);
        self::assertLessThan(
            self::OBJECTS,
            $queries,
            \sprintf(
                'reconcile issued %d queries for %d objects (%d pages); a per-object read path '
                .'issues at least one query per object, which is what this ticket removed',
                $queries,
                self::OBJECTS,
                $pages,
            ),
        );

        self::assertLessThan(
            self::THRESHOLD_BYTES,
            $peak,
            \sprintf('drift reconcile peaked at %0.1f MiB, must stay under 256 MiB', $peak / 1024 / 1024),
        );
        self::assertLessThan(
            96 * 1024 * 1024,
            $peak - $before,
            \sprintf('drift reconcile grew by %0.1f MiB; growth must track the page, not the catalogue', ($peak - $before) / 1024 / 1024),
        );
    }

    /**
     * Objects whose `attributes_indexed` cache is deliberately EMPTY while
     * their values exist — every object drifts, so reconcile rebuilds each one
     * and the measurement covers the repair path, not just the scan.
     */
    private function seedCatalogue(Tenant $tenant): void
    {
        $em = $this->em();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $attributes = [];
        for ($i = 1; $i <= self::VALUES_PER_OBJECT; ++$i) {
            $attribute = new Attribute('bench'.$i, ['en' => 'Bench '.$i], AttributeType::Text);
            $em->persist($attribute);
            $em->persist(new ObjectTypeAttribute($type, $attribute, false, $i));
            $attributes[] = $attribute;
        }
        $em->flush();

        for ($row = 1; $row <= self::OBJECTS; ++$row) {
            $object = new CatalogObject($type, 'DRIFT-'.$row);
            $em->persist($object);
            foreach ($attributes as $index => $attribute) {
                $em->persist(new ObjectValue(
                    $object,
                    $attribute,
                    ['value' => \sprintf('v%d-%d', $index, $row)],
                    Provenance::Manual,
                ));
            }
            if (0 === $row % 200) {
                $em->flush();
                $em->clear();
                // clear() detaches the tenant the assignment listener stamps on
                // every new row, so the context has to be re-armed with a
                // managed one before the next batch is persisted.
                $tenant = $em->find(Tenant::class, $tenant->getId()->toRfc4122()) ?? $tenant;
                self::getContainer()->get(TenantContext::class)->set($tenant);
                $type = $em->find(ObjectType::class, $type->getId()->toRfc4122());
                \assert($type instanceof ObjectType);
                $attributes = array_map(
                    static function (Attribute $a) use ($em): Attribute {
                        $fresh = $em->find(Attribute::class, $a->getId()->toRfc4122());
                        \assert($fresh instanceof Attribute);

                        return $fresh;
                    },
                    $attributes,
                );
            }
        }
        $em->flush();
        $em->clear();
        self::getContainer()->get(TenantContext::class)->set(
            $em->find(Tenant::class, $tenant->getId()->toRfc4122()) ?? $tenant,
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
