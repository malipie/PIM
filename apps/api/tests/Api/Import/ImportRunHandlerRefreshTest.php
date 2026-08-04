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
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * IMP2-2.3 (#1479) — lock-heartbeat contract: a long import must `refresh()`
 * the per-tenant bulk lock exactly once per processed chunk, so the lock TTL
 * never expires under the row loop (a chunk-less heartbeat would let a >1h
 * import lose the lock and let a second job start, breaking PROD-05).
 *
 * The handler has ~19 collaborators, so a pure unit double of all of them
 * would be mock-spaghetti that tests the mocks, not the loop. Instead this
 * pulls the real wired collaborators from the test container and substitutes
 * only the two seams that matter for the assertion: a {@see BulkOperationLock}
 * that hands out a spying {@see LockInterface} (counts refresh() calls), and a
 * `batchSize` of 2 so a 6-row file produces a deterministic 3 chunks. The spy
 * lock proves the heartbeat fires per chunk — ImportRunHandler.php lines
 * 314 / 337 (full + tail chunks).
 */
final class ImportRunHandlerRefreshTest extends CatalogApiTestCase
{
    #[Test]
    public function refreshesTheBulkLockOncePerChunk(): void
    {
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
            fileName: 'refresh.csv',
            fileSizeBytes: 64,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name']);
        $em->persist($session);
        $em->flush();

        // 6 data rows / batchSize 2 → 3 chunks (2 full + a 2-row "tail").
        $this->stageSourceFor(
            $session,
            "sku;name\nLK-1;A\nLK-2;B\nLK-3;C\nLK-4;D\nLK-5;E\nLK-6;F\n",
        );

        // The spy lock counts refresh() calls; the spy factory hands it to the
        // REAL (final) BulkOperationLock, so the handler acquires the spy via
        // the production acquire() path — no need to subclass the final lock.
        $spyLock = new class(new LockFactory(new InMemoryStore())->createLock(BulkOperationLock::keyFor($tenant), 3600.0, true)) implements SharedLockInterface {
            public int $refreshCalls = 0;

            public function __construct(private readonly LockInterface $delegate)
            {
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
                ++$this->refreshCalls;
                $this->delegate->refresh($ttl);
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

        $spyFactory = new class($spyLock) extends LockFactory {
            public function __construct(private readonly SharedLockInterface $lock)
            {
                parent::__construct(new InMemoryStore());
            }

            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                return $this->lock;
            }
        };

        $handler = new ImportRunHandler(
            entityManager: self::getContainer()->get(EntityManagerInterface::class),
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
            assets: self::getContainer()->get(\App\Asset\Domain\Repository\AssetRepositoryInterface::class),
            progressPublisher: self::getContainer()->get(\App\Import\Application\Service\ImportProgressPublisher::class),
            tenantContext: self::getContainer()->get(TenantContext::class),
            importsStorage: self::importsStorage(),
            bulkLock: new BulkOperationLock($spyFactory),
            managerRegistry: self::getContainer()->get(\Doctrine\Persistence\ManagerRegistry::class),
            assetUrlResolver: self::getContainer()->get(\App\Import\Application\Service\Media\AssetUrlResolver::class),
            messageBus: self::getContainer()->get(MessageBusInterface::class),
            bulkContext: self::getContainer()->get(\App\Catalog\Application\BulkContext::class),
            rowCells: self::getContainer()->get(\App\Import\Application\Service\ImportRowCells::class),
            categoryOps: self::getContainer()->get(\App\Import\Application\Service\ImportCategoryOps::class),
            batchSize: 2,
        );

        $handler->run($session);

        // 3 chunks were processed → exactly 3 heartbeats. A per-row or
        // chunk-less heartbeat would diverge from the chunk count.
        self::assertSame(3, $spyLock->refreshCalls, 'the bulk lock must be refreshed once per processed chunk');

        $em->clear();
        self::assertSame(6, $this->countObjects('LK-%'), 'precondition: every row was imported across the 3 chunks');
    }

    private static function importsStorage(): FilesystemOperator
    {
        return self::getContainer()->get('imports.storage');
    }

    private function stageSourceFor(ImportSession $session, string $csv): void
    {
        $tenant = $this->demoTenant();
        $path = tempnam(sys_get_temp_dir(), 'pim-refresh-').'.csv';
        file_put_contents($path, $csv);

        try {
            $staged = self::getContainer()->get(StagedFileService::class)->stage(
                $path,
                $session->getFileName(),
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
}
