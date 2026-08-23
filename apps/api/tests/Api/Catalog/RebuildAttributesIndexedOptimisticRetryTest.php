<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\Handler\AttributesIndexedRebuildFailedException;
use App\Catalog\Application\Handler\RebuildAttributesIndexedHandler;
use App\Catalog\Application\Message\ObjectValuesChangedMessage;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Uid\Uuid;

/**
 * IMP2-2.9 (#1485, ADR-0019 D11) — the async rebuild flushes PER ID, so a
 * version conflict on one object (a concurrent UI edit bumped `objects.version`
 * between read and flush) is retried with a fresh read instead of dead-lettering
 * the whole batch.
 *
 * Deterministic conflict: the three objects stay in the identity map at
 * `version = 1`; a raw `UPDATE` bumps the DB to `version = 2` behind Doctrine's
 * back. The handler's first `find()` returns the stale managed instance → its
 * flush hits the version guard and raises `OptimisticLockException`; the handler
 * clears the unit of work, re-reads the fresh row, and succeeds on retry. After
 * the first id's clear the remaining ids read fresh and flush cleanly — so every
 * object ends at `version = 3` (1 seeded → 2 raw bump → 3 rebuild flush) and the
 * handler never propagates the exception.
 */
final class RebuildAttributesIndexedOptimisticRetryTest extends CatalogApiTestCase
{
    #[Test]
    public function versionConflictOnOneObjectIsRetriedNotDeadLettered(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $ids = [];
        foreach (['RETRY-A', 'RETRY-B', 'RETRY-C'] as $code) {
            $object = new CatalogObject($productOt, $code);
            $em->persist($object);
            $ids[] = $object->getId();
        }
        $em->flush();
        // Keep the three instances managed at version=1 (NO clear): the handler's
        // find() must return these stale instances, not a fresh DB read.

        // Bump the DB version behind Doctrine's back — emulates a concurrent edit
        // committed between the handler's read and its flush.
        $conn = $em->getConnection();
        foreach ($ids as $id) {
            $conn->executeStatement(
                'UPDATE objects SET version = version + 1 WHERE id = :id',
                ['id' => $id->toRfc4122()],
            );
        }

        $handler = self::getContainer()->get(RebuildAttributesIndexedHandler::class);

        // Must NOT throw — the per-id retry absorbs the conflict.
        $handler(new ObjectValuesChangedMessage(array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $ids,
        ), $tenant->getId()));

        // Every object rebuilt + flushed exactly once after the raw bump: 1
        // (seed) → 2 (raw) → 3 (rebuild). version=3 on the FIRST id proves the
        // conflict was retried to success, not skipped or propagated.
        $em->clear();
        foreach ($ids as $id) {
            $version = $conn->fetchOne(
                'SELECT version FROM objects WHERE id = :id',
                ['id' => $id->toRfc4122()],
            );
            self::assertSame(3, (int) (\is_scalar($version) ? $version : 0), 'each object rebuilt once after the conflict');
        }
    }

    /**
     * AUD-039 / G-01 — when the conflict NEVER clears (a hot object edited on
     * every retry), the handler must NOT swallow it with a "successful" return:
     * after {@see RebuildAttributesIndexedHandler::MAX_REBUILD_RETRIES} attempts
     * on that id it logs an ERROR and throws
     * {@see AttributesIndexedRebuildFailedException}. The async retry policy then
     * re-delivers the batch and, once exhausted, dead-letters it to `failed`
     * (handled by {@see AttributesIndexedRebuildDeadLetterListener}) — drift
     * becomes loud instead of being hidden behind a Warning + success.
     *
     * Deterministic perpetual conflict: a `preFlush` listener bumps
     * `objects.version` on the EM's own connection right before EVERY flush, so
     * the managed instance the handler just read is always one version behind at
     * flush time → `OptimisticLockException` on attempt 1, 2 AND 3. `find()` after
     * each `resetManager()` re-reads the bumped row, the listener bumps again, and
     * the guard fails once more.
     */
    #[Test]
    public function versionConflictExhaustedRetriesThrowsAndLogsError(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $object = new CatalogObject($productOt, 'RETRY-EXHAUST');
        $em->persist($object);
        $em->flush();
        $id = $object->getId();
        $em->clear();

        // Perpetual conflict: bump the version (on the EM's connection) before
        // every flush so the entity the handler read is always stale. preFlush
        // fires before Doctrine opens its flush transaction, so the raw UPDATE
        // is safe here. Bound to the single object id so unrelated flushes
        // (none here) stay untouched.
        $idString = $id->toRfc4122();
        $listener = new class($idString) {
            public function __construct(private readonly string $idString)
            {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                $args->getObjectManager()->getConnection()->executeStatement(
                    'UPDATE objects SET version = version + 1 WHERE id = :id',
                    ['id' => $this->idString],
                );
            }
        };
        $em->getEventManager()->addEventListener([Events::preFlush], $listener);

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $errors = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->errors[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
                }
            }
        };

        $registry = self::getContainer()->get('doctrine');

        $handler = new RebuildAttributesIndexedHandler(
            $em,
            self::getContainer()->get(AttributesIndexedRebuilder::class),
            self::getContainer()->get(BulkReindexQueueInterface::class),
            $registry,
            $logger,
        );

        $caught = null;
        try {
            // AUD-039 — exhausting retries must NOT return "successfully": it
            // throws so the async transport re-delivers + eventually dead-letters.
            $handler(new ObjectValuesChangedMessage([$idString], $tenant->getId()));
        } catch (AttributesIndexedRebuildFailedException $e) {
            $caught = $e;
        } finally {
            // Detach the listener via a fresh manager so it cannot leak into the
            // teardown flush or sibling tests sharing this kernel boot.
            $registry->resetManager();
        }

        self::assertInstanceOf(
            AttributesIndexedRebuildFailedException::class,
            $caught,
            'exhausting retries must throw, not silently skip',
        );
        self::assertSame([$idString], $caught->objectIds, 'the exception carries the failed id');

        self::assertCount(1, $logger->errors, 'exactly one error log after exhausting retries');
        $error = $logger->errors[0];
        self::assertStringContainsString('rebuild failed', $error['message']);
        self::assertSame([$idString], $error['context']['object_ids'] ?? null);
        self::assertSame(1, $error['context']['count'] ?? null);
    }
}
