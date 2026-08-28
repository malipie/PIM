<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Support\InMemoryMercureHub;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2815 — a running import must leave a trace in the database while it runs,
 * not once it is over.
 *
 * Reported from two runs on 2026-08-10: for 21 minutes on dev and 12 on prod
 * the session row read `status: pending · total_rows: NULL · success_count: 0`
 * while the worker sat at 97–100% CPU actually writing rows. The sessions list
 * reads that row and said "oczekuje"; the detail screen reads Mercure and
 * showed real progress. The two screens contradicted each other, and — worse —
 * a hung run and a slow one were indistinguishable.
 *
 * The assertions below are about WHEN the state is visible, so the test
 * snapshots the session row on every flush and inspects the sequence, rather
 * than checking the end state and assuming the middle.
 */
final class ImportDurableProgressTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** Over one 200-row chunk, so the run flushes progress mid-file. */
    private const int ROWS = 250;

    #[Test]
    public function progressIsVisibleInTheDatabaseWhileTheRunIsStillWorking(): void
    {
        $em = $this->em();
        $tenant = $this->seedTenant();
        $type = $this->seedObjectType();
        $session = $this->seedSession($tenant, $type, self::ROWS);
        $sessionId = $session->getId();

        $probe = new SessionRowProbe($em->getConnection(), $sessionId->toRfc4122());
        $em->getEventManager()->addEventListener([Events::postFlush], $probe);

        try {
            self::getContainer()->get(ImportRunHandler::class)->run($session);
        } finally {
            $em->getEventManager()->removeEventListener([Events::postFlush], $probe);
        }

        self::assertNotEmpty($probe->snapshots, 'precondition: the run flushed at least once');

        // 1. The job announces itself before doing the work: the first
        //    persisted state already reads `running` with a start time, where
        //    the hub previously showed "oczekuje" for the whole run.
        $first = $probe->snapshots[0];
        self::assertSame('running', $first['status'], 'the row must read `running` from the first write on');
        self::assertNotNull($first['started_at'], 'start time must be persisted before the rows are worked through');

        // 2. The SIZE of the job is persisted before a single row is written —
        //    total_rows used to be set only after the loop, so the list showed
        //    "— wierszy" next to a job that had been running for 20 minutes.
        $beforeAnyRow = array_values(array_filter(
            $probe->snapshots,
            static fn (array $row): bool => 0 === $row['processed_rows'],
        ));
        self::assertNotSame(
            [],
            array_filter($beforeAnyRow, static fn (array $row): bool => self::ROWS === $row['total_rows']),
            'the row count must be persisted before the loop writes anything, not after it',
        );

        // 3. Progress moves DURING the run: a snapshot with a partial count is
        //    what separates "working" from "hung", and it is the number the
        //    sessions list renders.
        $midRun = array_values(array_filter(
            $probe->snapshots,
            static fn (array $row): bool => $row['processed_rows'] > 0 && $row['processed_rows'] < self::ROWS,
        ));
        self::assertNotEmpty(
            $midRun,
            'processed_rows must advance mid-run; a counter that only moves at the end cannot tell slow from stuck',
        );
        self::assertNotNull($midRun[0]['progress_updated_at'], 'a moving counter must carry when it last moved');

        // 4. The end state is exact and replaces the estimate it started from.
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $reloaded = self::getContainer()->get(ImportSessionRepositoryInterface::class)->findById($sessionId);
        self::assertInstanceOf(ImportSession::class, $reloaded);
        self::assertSame(self::ROWS, $reloaded->getTotalRows());
        self::assertSame(self::ROWS, $reloaded->getProcessedRows());
        self::assertSame(self::ROWS, $reloaded->getSuccessCount());
        self::assertNotNull($reloaded->getProgressUpdatedAt());
    }

    /**
     * #2815 — re-importing an unchanged export counts every row as `skipped`,
     * leaving BOTH success_count and error_count at 0. The sessions hub derived
     * its progress from the sum of those two, so a run doing real work read as
     * "nothing has happened" — the `success_count: 0` in the report. The
     * durable counter must not share that blind spot.
     */
    #[Test]
    public function aNoOpReImportStillReportsProgress(): void
    {
        $tenant = $this->seedTenant();
        $type = $this->seedObjectType();

        $tenantId = $tenant->getId();
        $typeId = $type->getId();

        $first = $this->seedSession($tenant, $type, self::ROWS, 'first');
        self::getContainer()->get(ImportRunHandler::class)->run($first);

        // run() clears the EntityManager, detaching everything held above.
        // The second file carries IDENTICAL values, so every row is a no-op.
        [$tenant, $type] = $this->rebind($tenantId, $typeId);
        $second = $this->seedSession($tenant, $type, self::ROWS, 'second', 'Same');
        $secondId = $second->getId();
        self::getContainer()->get(ImportRunHandler::class)->run($second);

        self::getContainer()->get(TenantContext::class)->set($tenant);
        $reloaded = self::getContainer()->get(ImportSessionRepositoryInterface::class)->findById($secondId);
        self::assertInstanceOf(ImportSession::class, $reloaded);

        self::assertSame(0, $reloaded->getSuccessCount(), 'precondition: an unchanged re-import writes nothing');
        self::assertSame(0, $reloaded->getErrorCount(), 'precondition: and fails nothing');
        self::assertSame(self::ROWS, $reloaded->getSkippedCount(), 'precondition: every row is a no-op skip');
        self::assertSame(
            self::ROWS,
            $reloaded->getProcessedRows(),
            'progress must count rows worked through, whatever the per-row outcome was',
        );
    }

    /**
     * Re-reads tenant + object type into the current EntityManager and re-arms
     * the tenant context, which a completed run left cleared.
     *
     * @return array{Tenant, ObjectType}
     */
    private function rebind(Uuid $tenantId, Uuid $typeId): array
    {
        $em = $this->em();
        $tenant = $em->find(Tenant::class, $tenantId->toRfc4122());
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $type = $em->find(ObjectType::class, $typeId->toRfc4122());
        \assert($type instanceof ObjectType);

        return [$tenant, $type];
    }

    private function seedTenant(): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant('progress-'.substr(uniqid(), -6), 'Progress Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        // The prod hub POSTs and forgets; the in-memory one would retain every
        // Update for the length of the run.
        $hub = self::getContainer()->get(InMemoryMercureHub::class);
        \assert($hub instanceof InMemoryMercureHub);
        $hub->stopRetaining();

        return $tenant;
    }

    private function seedObjectType(): ObjectType
    {
        $em = $this->em();
        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($type, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($type, $name, false, 2));
        $em->flush();

        return $type;
    }

    private function seedSession(
        Tenant $tenant,
        ObjectType $type,
        int $rows,
        string $label = 'progress',
        string $valuePrefix = 'Same',
    ): ImportSession {
        $em = $this->em();
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

        $csv = "sku;name\n";
        for ($row = 1; $row <= $rows; ++$row) {
            $csv .= \sprintf("PRG-%d;%s Product %d\n", $row, $valuePrefix, $row);
        }
        // get('imports.storage') is typed as the concrete Flysystem Filesystem.
        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/%s.csv', $tenant->getId()->toRfc4122(), $session->getId()->toRfc4122(), $label),
            $csv,
        );

        return $session;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

/**
 * @phpstan-type SessionRowSnapshot array{status: string, total_rows: ?int, processed_rows: int, progress_updated_at: ?string, started_at: ?string}
 *
 * Reads the committed session row after every flush. Reading through the
 * connection rather than the EntityManager is the point: it sees what a
 * concurrent request — the sessions list — would see at that moment, which is
 * the state this test is about.
 */
final class SessionRowProbe
{
    /** @var list<SessionRowSnapshot> */
    public array $snapshots = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $sessionId,
    ) {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        /** @var SessionRowSnapshot|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT status, total_rows, processed_rows, progress_updated_at, started_at FROM import_sessions WHERE id = :id',
            ['id' => $this->sessionId],
        );
        if (\is_array($row)) {
            $this->snapshots[] = $row;
        }
    }
}
