<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Application\Service\ImportRollbackService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Support\InMemoryMercureHub;
use App\Tests\Support\SqlQueryCounter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2814 — rolling back an import must cost memory proportional to a chunk, not
 * to the session.
 *
 * The reported failure was a full-catalogue re-import (13 895 objects, 51 304
 * undo rows) whose rollback exhausted the worker's 256 MiB in 1.65 s — before
 * mutating anything. Two separate steps each loaded the whole session at once:
 * the undo-log replay, and the `attributes_indexed` rebuild that follows it.
 *
 * Why this asserts on the identity map rather than on bytes: a session small
 * enough to build inside a test does not approach 256 MiB either way, so a
 * megabyte threshold would pass on the broken code and prove nothing. What
 * actually differs is *residency* — how many ObjectValue entities the
 * EntityManager holds at once. That is exact, cheap to observe, and it is the
 * quantity that scales into the OOM. The probe samples it on every hydration
 * and keeps the maximum.
 *
 * The bound is derived from the rollback's chunk size, so the test states a
 * contract ("residency tracks the chunk") rather than a magic number, and the
 * seeded volume is a clear multiple of it: on the pre-#2814 code the maximum
 * equals the session's entire value count and the assertion fails wide.
 */
final class ImportRollbackChunkedMemoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** Objects the two imports touch — three times the 200-object chunk. */
    private const int OBJECTS = 600;

    /** Value columns per object besides the SKU: 600 x 5 = 3 000 values. */
    private const int VALUE_COLUMNS = 5;

    /**
     * Peak ObjectValue residency the chunked rollback is allowed: two chunks'
     * worth, because the replay loads a chunk's values and the rebuild that
     * follows reloads the same chunk before the next clear(). A step walking
     * the session as a whole lands at OBJECTS x VALUE_COLUMNS instead.
     */
    private const int MAX_RESIDENT_VALUES = 2 * 200 * self::VALUE_COLUMNS;

    #[Test]
    public function rollbackHoldsOneChunkOfValuesAtATime(): void
    {
        $em = $this->em();

        $tenant = new Tenant('rollback-mem', 'Rollback Memory Tenant');
        $em->persist($tenant);
        $em->flush();
        $tenantId = $tenant->getId();
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $mapping = ['sku' => 'sku'];
        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $em->persist($sku);
        $em->persist(new ObjectTypeAttribute($type, $sku, false, 1));
        for ($column = 1; $column <= self::VALUE_COLUMNS; ++$column) {
            $code = 'col'.$column;
            $attribute = new Attribute($code, ['en' => 'Column '.$column], AttributeType::Text);
            $em->persist($attribute);
            $em->persist(new ObjectTypeAttribute($type, $attribute, false, $column + 1));
            $mapping[$code] = $code;
        }
        $em->flush();
        $typeId = $type->getId();

        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        // Prod POSTs progress to the hub and forgets it; the in-memory test hub
        // would retain every Update and pollute the residency measurement.
        $hub = self::getContainer()->get(InMemoryMercureHub::class);
        \assert($hub instanceof InMemoryMercureHub);
        $hub->stopRetaining();

        // Import #1 creates the objects; import #2 overwrites every value of
        // every one of them, which is what fills the undo log. Only the second
        // session is rolled back — rolling back the first would just delete the
        // objects it created and never reach the replay path at all.
        $this->runImport($tenantId, $typeId, $mapping, 'first', 'Old');
        $sessionId = $this->runImport($tenantId, $typeId, $mapping, 'second', 'New');

        $this->rebindTenant($tenantId);
        $session = self::getContainer()->get(ImportSessionRepositoryInterface::class)->findById($sessionId);
        self::assertInstanceOf(ImportSession::class, $session);
        self::assertSame('success', $session->getStatus()->value, 'precondition: the second import completed');

        $probe = new ObjectValueResidencyProbe($em);
        $em->getEventManager()->addEventListener([Events::postLoad], $probe);

        // #2818 — the endpoint claims the session and the worker runs it; this
        // test drives the run directly, so it makes the same claim first.
        $session->markRollbackStarted(new DateTimeImmutable());
        self::getContainer()->get(ImportSessionRepositoryInterface::class)->save($session);

        try {
            $report = self::getContainer()->get(ImportRollbackService::class)->run($session);
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $probe);
        }

        $totalValues = self::OBJECTS * self::VALUE_COLUMNS;
        self::assertGreaterThanOrEqual(
            $totalValues,
            $report['restoredValues'],
            'precondition: the rollback restored every overwritten value',
        );
        self::assertLessThanOrEqual(
            self::MAX_RESIDENT_VALUES,
            $probe->peak,
            \sprintf(
                'rollback held %d ObjectValue entities at once; a chunked walk allows %d '
                .'(the session holds %d — that shape is what exhausted 256 MiB)',
                $probe->peak,
                self::MAX_RESIDENT_VALUES,
                $totalValues,
            ),
        );

        // Bounding memory is only worth anything if the rollback still works.
        $em->clear();
        self::assertSame('Old-1', $this->valueOf('MEM-1', 'col1'), 'the pre-import value must be restored');
        self::assertSame(
            'Old-'.self::OBJECTS,
            $this->valueOf('MEM-'.self::OBJECTS, 'col1'),
            'the restore must reach the last chunk, not just the first',
        );
    }

    /** AUD-DATA-001 (#3020) — object hydration must cost one SELECT per chunk. */
    #[Test]
    #[Group('import-benchmark')]
    public function rollbackObjectSelectCountDoesNotScaleWithinAChunk(): void
    {
        $one = $this->countObjectSelectsForRollback(1, 'rollback-qc-one', 'QC1');
        $hundred = $this->countObjectSelectsForRollback(100, 'rollback-qc-hundred', 'QC100');

        self::assertSame(1, $one, 'precondition: one rollback chunk needs one object hydration query');
        self::assertSame(
            $one,
            $hundred,
            \sprintf(
                'objects SELECT count must be constant within one 200-object chunk: 1 object=%d, 100 objects=%d',
                $one,
                $hundred,
            ),
        );
    }

    private function countObjectSelectsForRollback(int $objectCount, string $tenantCode, string $skuPrefix): int
    {
        $em = $this->em();
        $tenant = new Tenant($tenantCode, 'Rollback query-count tenant');
        $em->persist($tenant);
        $em->flush();
        $tenantId = $tenant->getId();
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($type);
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($type, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($type, $name, false, 2));
        $em->flush();
        $typeId = $type->getId();

        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $hub = self::getContainer()->get(InMemoryMercureHub::class);
        \assert($hub instanceof InMemoryMercureHub);
        $hub->stopRetaining();

        $this->runSizedImport($tenantId, $typeId, $objectCount, $skuPrefix, 'first', 'Old');
        $sessionId = $this->runSizedImport($tenantId, $typeId, $objectCount, $skuPrefix, 'second', 'New');

        $this->rebindTenant($tenantId);
        $session = self::getContainer()->get(ImportSessionRepositoryInterface::class)->findById($sessionId);
        self::assertInstanceOf(ImportSession::class, $session);
        $session->markRollbackStarted(new DateTimeImmutable());
        self::getContainer()->get(ImportSessionRepositoryInterface::class)->save($session);

        $counter = self::getContainer()->get(SqlQueryCounter::class);
        self::assertInstanceOf(SqlQueryCounter::class, $counter);
        $counter->start();

        try {
            \memory_reset_peak_usage();
            $report = self::getContainer()->get(ImportRollbackService::class)->run($session);
            $peak = \memory_get_peak_usage(true);

            self::assertSame($objectCount * 2, $report['restoredValues'], 'each object restores sku + name');
            self::assertLessThan(
                256 * 1024 * 1024,
                $peak,
                \sprintf('rollback of %d objects peaked at %0.1f MiB', $objectCount, $peak / 1024 / 1024),
            );

            // Count only entity-hydration SELECTs. Optimistic-lock version
            // probes are writes' fixed ORM cost and raw COUNT/id-plan queries
            // are separate phases; the audited N+1 is `find()` issuing
            // `WHERE id = ?` here.
            return $counter->countMatching('/\bFROM\s+objects\s+\w+\s+WHERE\s+\(?\s*\w+\.id\s+(?:=|IN\s*\()/i');
        } finally {
            $counter->stop();
        }
    }

    private function runSizedImport(
        Uuid $tenantId,
        Uuid $typeId,
        int $objectCount,
        string $skuPrefix,
        string $label,
        string $valuePrefix,
    ): Uuid {
        $em = $this->em();
        $tenant = $this->rebindTenant($tenantId);
        $type = $em->find(ObjectType::class, $typeId->toRfc4122());
        \assert($type instanceof ObjectType);

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $type,
            fileName: $label.'.csv',
            fileSizeBytes: 1024,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        $csv = "sku;name\n";
        for ($row = 1; $row <= $objectCount; ++$row) {
            $csv .= \sprintf("%s-%d;%s-%d\n", $skuPrefix, $row, $valuePrefix, $row);
        }

        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/%s.csv', $tenantId->toRfc4122(), $sessionId->toRfc4122(), $label),
            $csv,
        );
        self::getContainer()->get(ImportRunHandler::class)->run($session);

        return $sessionId;
    }

    /**
     * Runs one import over {@see OBJECTS} rows and returns its session id.
     *
     * Entities are re-fetched by id because {@see ImportRunHandler::run()}
     * clears the EntityManager per chunk, detaching everything the caller held.
     *
     * @param array<string, string> $mapping
     */
    private function runImport(Uuid $tenantId, Uuid $typeId, array $mapping, string $label, string $prefix): Uuid
    {
        $em = $this->em();
        $tenant = $this->rebindTenant($tenantId);
        $type = $em->find(ObjectType::class, $typeId->toRfc4122());
        \assert($type instanceof ObjectType);

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $type,
            fileName: $label.'.csv',
            fileSizeBytes: 1024,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping($mapping);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        $header = ['sku'];
        for ($column = 1; $column <= self::VALUE_COLUMNS; ++$column) {
            $header[] = 'col'.$column;
        }
        $csv = implode(';', $header)."\n";
        for ($row = 1; $row <= self::OBJECTS; ++$row) {
            $cells = ['MEM-'.$row];
            for ($column = 1; $column <= self::VALUE_COLUMNS; ++$column) {
                $cells[] = \sprintf('%s-%d', 1 === $column ? $prefix : $prefix.'c'.$column, $row);
            }
            $csv .= implode(';', $cells)."\n";
        }

        // get('imports.storage') is typed as the concrete Flysystem Filesystem.
        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/%s.csv', $tenantId->toRfc4122(), $sessionId->toRfc4122(), $label),
            $csv,
        );

        self::getContainer()->get(ImportRunHandler::class)->run($session);

        return $sessionId;
    }

    /** Re-reads the tenant into the current EntityManager and re-arms the context. */
    private function rebindTenant(Uuid $tenantId): Tenant
    {
        $tenant = $this->em()->find(Tenant::class, $tenantId->toRfc4122());
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        return $tenant;
    }

    private function valueOf(string $sku, string $attributeCode): ?string
    {
        $raw = $this->em()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT v.value ->> 'value'
                FROM object_values v
                JOIN objects o ON o.id = v.object_id
                JOIN attributes a ON a.id = v.attribute_id
                WHERE o.code = :sku AND a.code = :code
                SQL,
            ['sku' => $sku, 'code' => $attributeCode],
        );

        return \is_string($raw) ? $raw : null;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

/**
 * Samples how many ObjectValue entities the EntityManager holds, on every
 * hydration, and keeps the maximum. postLoad fires once the entity is already
 * in the identity map, so the count includes the one just loaded.
 */
final class ObjectValueResidencyProbe
{
    public int $peak = 0;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        if (!$args->getObject() instanceof ObjectValue) {
            return;
        }

        $resident = \count($this->em->getUnitOfWork()->getIdentityMap()[ObjectValue::class] ?? []);
        if ($resident > $this->peak) {
            $this->peak = $resident;
        }
    }
}
