<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Domain\Entity\ImportProfile;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Import\Domain\Message\ImportRunMessage;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use App\Tests\Support\InMemoryMercureHub;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * IMP2-1.10 (#1473) — first tests for the async import path. The dev/test
 * MESSENGER_IMPORT_TRANSPORT_DSN is `sync://`, so a routed ImportRunMessage
 * still executes inline; these tests assert the ROUTING (the message targets
 * the dedicated `import` transport), the end-to-end handler outcome for a
 * file over the 50-row sync threshold, re-delivery idempotency, and the
 * recoverable-retry contract on the per-tenant bulk lock.
 */
final class ImportRunHandlerAsyncTest extends CatalogApiTestCase
{
    private const int LARGE = 60; // > StartImportController::SYNC_THRESHOLD_ROWS

    #[Test]
    public function importRunMessageRoutesToDedicatedTransport(): void
    {
        // Audit MEDIUM-007 posture: the message must target an explicit
        // transport (the dedicated `import` queue), never implicit default.
        $locator = self::getContainer()->get('messenger.senders_locator');
        self::assertInstanceOf(SendersLocatorInterface::class, $locator);

        $message = new ReflectionClass(ImportRunMessage::class)->newInstanceWithoutConstructor();
        $aliases = [];
        foreach ($locator->getSenders(new Envelope($message)) as $alias => $_sender) {
            $aliases[] = $alias;
        }

        self::assertContains('import', $aliases, 'ImportRunMessage must route to the dedicated import transport.');
    }

    #[Test]
    public function largeFileImportsEndToEndViaAsyncPath(): void
    {
        // > 50 data rows → the controller routes through ImportRunMessage
        // (the xlsx/large path); the sync transport completes it inline.
        $this->seedSkuName();
        $em = $this->em();

        $rows = ['sku;name'];
        for ($i = 1; $i <= self::LARGE; ++$i) {
            $rows[] = \sprintf('ASYNC-%d;Product %d', $i, $i);
        }
        $sessionId = $this->upload(implode("\n", $rows)."\n");

        $em->clear();
        $session = $em->find(ImportSession::class, Uuid::fromString($sessionId));
        \assert($session instanceof ImportSession);
        self::assertSame(ImportSessionStatus::Success, $session->getStatus(), 'async-routed import completes');
        self::assertSame(self::LARGE, $session->getSuccessCount());
        self::assertSame(self::LARGE, $session->getTotalRows());

        $persisted = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM objects WHERE code LIKE 'ASYNC-%'");
        self::assertSame(self::LARGE, (int) (\is_scalar($persisted) ? $persisted : 0));
    }

    #[Test]
    public function reDeliveryIsIdempotentUnderUpsert(): void
    {
        // A re-delivered import (UPSERT) must not duplicate objects — the
        // resilience contract the worker relies on after a crash/redeliver
        // (full offset checkpoint is IMP2-2.3; UPSERT idempotency holds now).
        $this->seedSkuName();
        $em = $this->em();

        $rows = ['sku;name'];
        for ($i = 1; $i <= self::LARGE; ++$i) {
            $rows[] = \sprintf('IDEM-%d;Product %d', $i, $i);
        }
        $csv = implode("\n", $rows)."\n";

        $this->upload($csv);
        $secondId = $this->upload($csv);

        $em->clear();
        $count = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM objects WHERE code LIKE 'IDEM-%'");
        self::assertSame(self::LARGE, (int) (\is_scalar($count) ? $count : 0), 're-delivery must not duplicate objects');

        $second = $em->find(ImportSession::class, Uuid::fromString($secondId));
        \assert($second instanceof ImportSession);
        // IMP2-2.6 — the second (identical) run no longer re-writes every row:
        // the compare-values diff detects unchanged value+provenance and skips
        // the UPDATE, so re-delivery is not just object-idempotent but write-free.
        self::assertSame(0, $second->getUpdatedCount(), 'second run rewrites nothing');
        self::assertSame(self::LARGE, $second->getSkippedCount(), 'second run skips every unchanged row');
    }

    #[Test]
    public function bulkLockCollisionThrowsRecoverable(): void
    {
        // PROD-05 — a second concurrent import for the same tenant must surface
        // as a recoverable Messenger failure (retry with backoff), never a
        // dead-letter and never a corrupted session.
        $this->seedSkuName();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $productOt,
            fileName: 'locked.csv',
            fileSizeBytes: 64,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        // Hold the per-tenant bulk lock so the handler cannot acquire it.
        $lock = self::getContainer()->get(BulkOperationLock::class)->acquire($tenant);
        self::assertNotNull($lock, 'precondition: the test holds the bulk lock');

        try {
            $handler = self::getContainer()->get(ImportRunHandler::class);
            $this->expectException(RecoverableMessageHandlingException::class);
            $handler(new ImportRunMessage($sessionId, $tenant->getId()));
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function bulkImportRebuildsAttributesIndexedViaAsyncPath(): void
    {
        // IMP2-2.6 — the row phase runs under BulkContext, so the per-flush sync
        // rebuild is suppressed; the end-of-run ObjectValuesChangedMessage (sync
        // transport in test) must rebuild attributes_indexed inline instead.
        // Prove every imported object ends up with a populated cache — i.e. the
        // async path actually replaced the suppressed sync rebuild (the older
        // count/object assertions would not have caught an empty cache).
        $this->seedSkuName();
        $em = $this->em();

        $rows = ['sku;name'];
        for ($i = 1; $i <= self::LARGE; ++$i) {
            $rows[] = \sprintf('IDX-%d;Product %d', $i, $i);
        }
        $this->upload(implode("\n", $rows)."\n");
        // The end-of-run rebuild is dispatched to the `async` transport. Under CI
        // (`in-memory://`) it sits buffered; replay it so the cache is rebuilt.
        $this->consumeAsyncQueue();

        $em->clear();
        $indexed = $em->getConnection()->fetchOne("SELECT attributes_indexed FROM objects WHERE code = 'IDX-1'");
        self::assertIsString($indexed);
        $decoded = json_decode($indexed, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('sku', $decoded, 'attributes_indexed rebuilt by the async handler');
        self::assertArrayHasKey('name', $decoded);

        $emptyCount = $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM objects WHERE code LIKE 'IDX-%' AND attributes_indexed = '{}'::jsonb",
        );
        self::assertSame(0, (int) (\is_scalar($emptyCount) ? $emptyCount : 1), 'no imported object left with an empty attributes_indexed cache');
    }

    #[Test]
    public function batchImportThrottlesProgressInsteadOfPerRowEvents(): void
    {
        // IMP2-2.6 — the batch path must not emit a Mercure event per row (50k
        // rows would be 50k hub POSTs). Prove a multi-chunk import publishes a
        // handful of throttled `progress` snapshots and ZERO `row_processed`.
        // Drive the handler directly: a functional request reboots the kernel and
        // drops the in-memory hub's captured updates, so the hub assertion must
        // run in-process.
        $this->seedSkuName();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $product,
            fileName: 'throttle.csv',
            fileSizeBytes: 1024,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        $count = 500; // > one chunk (batchSize 200) → several throttled snapshots
        $csv = "sku;name\n";
        for ($i = 1; $i <= $count; ++$i) {
            $csv .= \sprintf("THR-%d;Product %d\n", $i, $i);
        }
        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/throttle.csv', $tenant->getId()->toRfc4122(), $sessionId->toRfc4122()),
            $csv,
        );

        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $hub = self::getContainer()->get(InMemoryMercureHub::class);
        \assert($hub instanceof InMemoryMercureHub);
        $hub->reset();

        self::getContainer()->get(ImportRunHandler::class)->run($session);

        $types = [];
        foreach ($hub->getCapturedUpdates() as $update) {
            $decoded = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
            if (\is_array($decoded) && \is_string($decoded['type'] ?? null)) {
                $types[] = $decoded['type'];
            }
        }

        self::assertNotContains('row_processed', $types, 'batch path must not emit a Mercure event per row');
        $progress = array_filter($types, static fn (string $t): bool => 'progress' === $t);
        self::assertGreaterThanOrEqual(1, \count($progress), 'a throttled progress snapshot is published; all types: ['.implode(',', $types).']');
        self::assertLessThanOrEqual(20, \count($progress), \sprintf('progress is throttled, not per-row (got %d for %d rows)', \count($progress), $count));
    }

    #[Test]
    public function importAbortsWhenErrorRatioExceedsProfileThreshold(): void
    {
        // IMP2-2.7 (#1483) — a profile Allowed-Errors threshold aborts the run
        // (Failed) once the blocking-error ratio crosses it. Half the rows have a
        // blank sku (missing_required → blocking), so ~50% >> the 10% threshold.
        $this->seedSkuName();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $profile = new ImportProfile(Uuid::v7(), 'abort-profile', $product);
        $profile->assignTenant($tenant);
        $profile->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $profile->setAllowedErrorsPct(10);
        $em->persist($profile);

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $product,
            fileName: 'abort.csv',
            fileSizeBytes: 2048,
            profile: $profile,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        $csv = "sku;name\n";
        for ($i = 1; $i <= 60; ++$i) {
            $csv .= 0 === $i % 2 ? \sprintf("AB-%d;Name %d\n", $i, $i) : \sprintf(";Name %d\n", $i);
        }
        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/abort.csv', $tenant->getId()->toRfc4122(), $sessionId->toRfc4122()),
            $csv,
        );

        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        self::getContainer()->get(ImportRunHandler::class)->run($session);

        $em->clear();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $reloaded = $em->find(ImportSession::class, $sessionId);
        \assert($reloaded instanceof ImportSession);
        self::assertSame(
            ImportSessionStatus::Failed,
            $reloaded->getStatus(),
            'run aborts to Failed when the error ratio exceeds the profile threshold',
        );
    }

    /**
     * Drain the in-memory `async` transport so the end-of-run attributes_indexed
     * rebuild runs inside the test. Local dev uses `sync://` (the dispatch is
     * in-band, so this is a no-op); CI overrides `async` to `in-memory://`, where
     * the ObjectValuesChangedMessage would otherwise sit unhandled. Re-dispatching
     * with a {@see ReceivedStamp} replays it through the full middleware stack
     * (tenant rebind + RLS GUC from the message's TenantStamp) before the handler.
     */
    private function consumeAsyncQueue(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        if (!$transport instanceof InMemoryTransport) {
            return;
        }
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($transport->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $transport->ack($envelope);
        }
    }

    private function upload(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-async-').'.csv';
        file_put_contents($path, $csv);

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new UploadedFile($path, 'async.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $id = $body['id'];
            \assert(\is_string($id));

            return $id;
        } finally {
            @unlink($path);
        }
    }

    private function seedSkuName(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($product, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($product, $name, false, 2));
        $em->flush();
    }
}
