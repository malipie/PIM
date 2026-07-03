<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Application\Service\StagedFileService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportMode;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * IMP2-2.3 (#1479) — real pause / resume / cancel + crash-resilient checkpoint.
 *
 * Covers the state endpoints (transitions + conflicts + owner-scoping), the
 * resume re-dispatch (the worker actually picks the session back up), and the
 * checkpoint-skip: a resumed run skips writes at/below the checkpoint, keeps
 * the persisted counters, and reaches success without duplicating rows.
 */
final class ImportPauseResumeTest extends CatalogApiTestCase
{
    #[Test]
    public function pauseFlipsRunningToPaused(): void
    {
        $session = $this->persistRunningSession();

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/pause', $session->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertSame('paused', $this->decode($client)['status']);
    }

    #[Test]
    public function cancelStopsTheSession(): void
    {
        $session = $this->persistRunningSession();

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/cancel', $session->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->decode($client)['status']);
    }

    #[Test]
    public function pauseFromNonRunningConflicts(): void
    {
        $session = $this->persistRunningSession();
        // Drive it terminal first so pause has no legal transition.
        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/cancel', $session->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();

        $client->request('POST', \sprintf('/api/import-sessions/%s/pause', $session->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function crossUserPauseYields404(): void
    {
        // Session owned by a DIFFERENT user in the same tenant.
        $session = $this->persistRunningSession(Uuid::v7());

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/pause', $session->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function resumeReDispatchesTheWorkerToCompletion(): void
    {
        $this->seedSkuName();
        $em = $this->em();

        // A paused session whose source file is already staged at the session
        // key, checkpoint 0 → resume re-runs it from the top and completes.
        $session = $this->persistRunningSession();
        $session->markPaused();
        $em->flush();
        $this->stageSourceFor($session, "sku;name\nRES-1;A\nRES-2;B\nRES-3;C\n");

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/resume', $session->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();

        // The dev/test import transport is sync:// — the re-dispatched message
        // ran inline, so the session is finished by the time we reload it.
        $em->clear();
        $reloaded = $em->find(ImportSession::class, $session->getId()->toRfc4122());
        self::assertInstanceOf(ImportSession::class, $reloaded);
        self::assertSame(ImportSessionStatus::Success, $reloaded->getStatus(), 'resume must re-run the import to completion');
        self::assertSame(3, $this->countObjects('RES-%'));
    }

    #[Test]
    public function resumeSkipsCheckpointedRowsAndContinuesCounters(): void
    {
        $this->seedSkuName();
        $em = $this->em();

        // Simulate a session paused after 3 of 6 rows: counters + checkpoint
        // persisted, none of SK-1..6 written yet for THIS run. The resumed run
        // must skip rows ≤ 3 (no writes) and create only rows 4..6, leaving
        // success_count == total rows and zero duplicates.
        $session = $this->persistRunningSession();
        $session->configureRun(ImportMode::Create, null);
        $session->incrementSuccess();
        $session->incrementSuccess();
        $session->incrementSuccess();
        $session->recordCheckpoint(3, 'rows');
        $em->flush();

        $this->stageSourceFor($session, "sku;name\nSK-1;A\nSK-2;B\nSK-3;C\nSK-4;D\nSK-5;E\nSK-6;F\n");

        self::getContainer()->get(ImportRunHandler::class)->run($session);

        $em->clear();
        // Only rows past the checkpoint were written.
        self::assertSame(3, $this->countObjects('SK-%'), 'rows at/below the checkpoint must not be (re)created');
        self::assertSame(0, $this->countObjects('SK-1'));
        self::assertSame(0, $this->countObjects('SK-3'));
        self::assertSame(1, $this->countObjects('SK-4'));
        self::assertSame(1, $this->countObjects('SK-6'));

        $reloaded = $em->find(ImportSession::class, $session->getId()->toRfc4122());
        self::assertInstanceOf(ImportSession::class, $reloaded);
        self::assertSame(ImportSessionStatus::Success, $reloaded->getStatus());
        // 3 carried over + 3 created on resume == the 6 file rows, no double count.
        self::assertSame(6, $reloaded->getSuccessCount());
    }

    #[Test]
    public function haltMidRunWritesCheckpointThenResumeCompletesWithoutDuplicates(): void
    {
        // IMP2-2.3 crash-resilience: a run that is HALTED after a real chunk
        // commit must leave the just-written rows + the checkpoint persisted,
        // and the resume must finish the file WITHOUT re-creating any halted
        // row. Unlike resumeSkipsCheckpointedRows..., this drives a real run
        // first (rows + checkpoint genuinely written, not hand-staged) so it
        // proves the kill→checkpoint→resume seam is duplicate-free end to end.
        $this->seedSkuName();
        $em = $this->em();
        $tenant = $this->demoTenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $session = new ImportSession(
            userId: $this->adminUserId(),
            targetObjectType: $product,
            fileName: 'halt.csv',
            fileSizeBytes: 64,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $session->configureRun(ImportMode::Create, null);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        // 6 rows / batchSize 2 → chunks of 2. The spy lock flips the session to
        // `paused` in the DB the first time the handler refreshes the lock
        // (i.e. right after the first chunk committed), simulating the operator
        // pressing Pauza mid-run. The next chunk's refreshSession() reloads the
        // paused status and the handler stops gracefully on the checkpoint.
        $this->stageSourceFor($session, "sku;name\nHALT-1;A\nHALT-2;B\nHALT-3;C\nHALT-4;D\nHALT-5;E\nHALT-6;F\n");

        // A spy lock that flips the session to `paused` in the DB on the first
        // heartbeat (right after the first chunk committed), then a spy factory
        // that hands it to the REAL (final) BulkOperationLock — so the handler
        // acquires it via the production acquire() path without subclassing the
        // final lock.
        $conn = $em->getConnection();
        $pausingLock = new class($conn, $sessionId->toRfc4122(), new LockFactory(new InMemoryStore())->createLock('halt-test', 3600.0, true)) implements SharedLockInterface {
            public function __construct(
                private readonly \Doctrine\DBAL\Connection $connection,
                private readonly string $sessionId,
                private readonly LockInterface $delegate,
            ) {
            }

            public function acquire(bool $blocking = false): bool
            {
                return $this->delegate->acquire($blocking);
            }

            public function acquireRead(bool $blocking = false): bool
            {
                return $this->delegate->acquire($blocking);
            }

            public function refresh(?float $ttl = null): void
            {
                // First heartbeat == first chunk committed: flip to paused so
                // the next refreshSession() observes the operator's intent.
                $this->connection->executeStatement(
                    "UPDATE import_sessions SET status = 'paused', paused_at = NOW() WHERE id = :id AND status = 'running'",
                    ['id' => $this->sessionId],
                );
            }

            public function isAcquired(): bool
            {
                return $this->delegate->isAcquired();
            }

            public function release(): void
            {
                $this->delegate->release();
            }

            public function isExpired(): bool
            {
                return $this->delegate->isExpired();
            }

            public function getRemainingLifetime(): ?float
            {
                return $this->delegate->getRemainingLifetime();
            }
        };

        $stubFactory = new class($pausingLock) extends LockFactory {
            public function __construct(private readonly SharedLockInterface $lock)
            {
                parent::__construct(new InMemoryStore());
            }

            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                return $this->lock;
            }
        };

        $this->buildHandler(new BulkOperationLock($stubFactory), 2)->run($session);

        // Halted mid-run: paused, a checkpoint persisted, and SOME but not all
        // rows committed — the real write the resume must not duplicate.
        $em->clear();
        $halted = $em->find(ImportSession::class, $sessionId);
        self::assertInstanceOf(ImportSession::class, $halted);
        self::assertSame(ImportSessionStatus::Paused, $halted->getStatus(), 'mid-run halt must leave the session paused');
        self::assertNotNull($halted->getCheckpointOffset(), 'a checkpoint must survive the halt');
        $writtenAtHalt = $this->countObjects('HALT-%');
        self::assertGreaterThan(0, $writtenAtHalt, 'the committed chunk(s) must persist across the halt');
        self::assertLessThan(6, $writtenAtHalt, 'the halt must stop the run before the whole file is imported');

        // Resume via the API: the worker picks the session back up (sync
        // transport runs it inline) and finishes the file.
        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/resume', $sessionId->toRfc4122()));
        self::assertResponseIsSuccessful();

        $em->clear();
        $resumed = $em->find(ImportSession::class, $sessionId);
        self::assertInstanceOf(ImportSession::class, $resumed);
        self::assertSame(ImportSessionStatus::Success, $resumed->getStatus(), 'resume must finish the import');

        // The crash-resilience contract: every SKU exists EXACTLY once — the
        // resume re-read the halted rows but skipped their writes (checkpoint),
        // so there are 6 objects, not 6 + the re-imported chunk(s).
        self::assertSame(6, $this->countObjects('HALT-%'), 'resume must complete the file with no duplicates');
        for ($i = 1; $i <= 6; ++$i) {
            self::assertSame(1, $this->countObjects(\sprintf('HALT-%d', $i)), \sprintf('SKU HALT-%d must exist exactly once', $i));
        }
    }

    /**
     * Build a real ImportRunHandler from the container with only the bulk lock
     * and batch size substituted, so a mid-run halt can be triggered between
     * deterministic chunks without re-wiring the ~19 real collaborators.
     */
    private function buildHandler(BulkOperationLock $bulkLock, int $batchSize): ImportRunHandler
    {
        $storage = self::getContainer()->get('imports.storage');

        return new ImportRunHandler(
            entityManager: self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class),
            sessions: self::getContainer()->get(\App\Import\Domain\Repository\ImportSessionRepositoryInterface::class),
            rowReader: self::getContainer()->get(\App\Import\Application\Service\ImportRowReader::class),
            validator: self::getContainer()->get(\App\Import\Application\Service\ImportValidationService::class),
            creator: self::getContainer()->get(\App\Import\Application\Service\ImportObjectCreator::class),
            optionAutoCreator: self::getContainer()->get(\App\Import\Application\Service\OptionAutoCreator::class),
            attributeAutoCreator: self::getContainer()->get(\App\Import\Application\Service\AttributeAutoCreator::class),
            objectResolver: self::getContainer()->get(\App\Import\Application\Service\ObjectResolver::class),
            relationStep: self::getContainer()->get(\App\Import\Application\Service\RelationImportStep::class),
            undoLogger: self::getContainer()->get(\App\Import\Application\Service\ImportUndoLogger::class),
            columnGrammar: self::getContainer()->get(\App\Import\Application\Service\ImportColumnGrammar::class),
            valueWriter: self::getContainer()->get(\App\Catalog\Application\BatchValueWriter::class),
            crossFieldRules: self::getContainer()->get(\App\Catalog\Application\CrossFieldRulesValidator::class),
            attributeRepository: self::getContainer()->get(\App\Catalog\Domain\Repository\AttributeRepositoryInterface::class),
            catalogObjects: self::getContainer()->get(\App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface::class),
            objectCategories: self::getContainer()->get(\App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface::class),
            assets: self::getContainer()->get(\App\Asset\Domain\Repository\AssetRepositoryInterface::class),
            progressPublisher: self::getContainer()->get(\App\Import\Application\Service\ImportProgressPublisher::class),
            tenantContext: self::getContainer()->get(TenantContext::class),
            importsStorage: $storage,
            bulkLock: $bulkLock,
            managerRegistry: self::getContainer()->get(\Doctrine\Persistence\ManagerRegistry::class),
            assetUrlResolver: self::getContainer()->get(\App\Import\Application\Service\Media\AssetUrlResolver::class),
            messageBus: self::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class),
            bulkContext: self::getContainer()->get(\App\Catalog\Application\BulkContext::class),
            batchSize: $batchSize,
        );
    }

    private function persistRunningSession(?Uuid $ownerOverride = null): ImportSession
    {
        $em = $this->em();
        $tenant = $this->demoTenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $session = new ImportSession(
            userId: $ownerOverride ?? $this->adminUserId(),
            targetObjectType: $product,
            fileName: 'resume.csv',
            fileSizeBytes: 64,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $session->markRunning();
        $em->persist($session);
        $em->flush();

        return $session;
    }

    private function stageSourceFor(ImportSession $session, string $csv): void
    {
        $tenant = $this->demoTenant();
        $path = tempnam(sys_get_temp_dir(), 'pim-resume-').'.csv';
        file_put_contents($path, $csv);

        try {
            $staged = self::getContainer()->get(StagedFileService::class)->stage(
                $path,
                'resume.csv',
                (int) filesize($path),
                $tenant,
                $session->getUserId(),
            );
            $key = \sprintf(
                '%s/%s/%s',
                $tenant->getId()->toRfc4122(),
                $session->getId()->toRfc4122(),
                $session->getFileName(),
            );
            self::getContainer()->get(StagedFileService::class)->copyToKey($staged, $key);
        } finally {
            @unlink($path);
        }
    }

    private function countObjects(string $like): int
    {
        $count = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE code LIKE :like',
            ['like' => $like],
        );

        return (int) (\is_scalar($count) ? $count : 0);
    }

    private function demoTenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function adminUserId(): Uuid
    {
        $user = self::getContainer()->get(\App\Identity\Domain\Repository\UserRepositoryInterface::class)
            ->findByEmail(self::ADMIN_EMAIL);
        \assert(null !== $user);

        return $user->getId();
    }

    private function seedSkuName(): void
    {
        $em = $this->em();
        $tenant = $this->demoTenant();
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

    /**
     * @return array<mixed>
     */
    private function decode(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $decoded = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
