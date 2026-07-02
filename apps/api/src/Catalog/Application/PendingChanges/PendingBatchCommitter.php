<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\BatchValueWriter;
use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Message\ObjectValuesChangedMessage;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use App\Catalog\Contracts\Command\PendingBatchCommitResult;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Provenance;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * AGENT-P3-02 (#1962) — step 5 of the agent cycle: after the operator
 * accepts, the pending batch commits to the catalog through the SAME
 * bulk machinery as manual bulk edits:
 *
 *   - accept() is a status-guarded DQL transition (pending -> accepted);
 *     a second commit finds zero pending rows and no-ops — idempotency
 *     without any extra bookkeeping. Rejected/expired batches transition
 *     nothing and therefore commit nothing (SEC).
 *   - the whole commit runs in ONE DB transaction — a mid-batch failure
 *     rolls back values, logs, session AND the accept transition, so the
 *     batch stays pending and re-approvable (all-or-nothing).
 *   - values write through BatchValueWriter with Provenance::Agent and
 *     the run's provenance_meta; BulkContext opts out the synchronous
 *     indexed listeners and one ObjectValuesChangedMessage per chunk
 *     triggers the async attributes_indexed rebuild (import pattern).
 *   - BulkSession (source=cmd_k_agent) + per-change bulk_logs rows use
 *     the multi_attribute_edit action shape, so the existing 24h
 *     rollback path (BulkRollbackHandler) can replay old values (P3-04).
 *
 * MVP: all-or-nothing (no partial accept), global values only — the
 * same basis P3-01 materialized.
 */
final readonly class PendingBatchCommitter implements PendingBatchCommitPort
{
    private const int CHUNK = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private PendingChangesPort $pendingChanges,
        private BatchValueWriter $valueWriter,
        private BulkContext $bulkContext,
        private BulkReindexQueueInterface $reindexQueue,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function commitAcceptedBatch(Uuid $batchId, Uuid $approvedBy, array $provenanceMeta = []): PendingBatchCommitResult
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot commit a pending batch without a current tenant.');
        }
        $tenantId = $tenant->getId()->toRfc4122();

        // A 10k-object batch outlives PHP's default HTTP timeout.
        set_time_limit(0);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            if (0 === $this->pendingChanges->accept($batchId)) {
                $connection->rollBack();

                return PendingBatchCommitResult::nothingToCommit();
            }

            $perObject = $this->collectAcceptedValueChanges($batchId);
            if ([] === $perObject) {
                $connection->rollBack();

                return PendingBatchCommitResult::nothingToCommit();
            }

            // collectAcceptedValueChanges() streamed with clear(), which
            // detached the Tenant in TenantContext — re-attach a managed
            // reference so the listener can stamp the BulkSession.
            $this->tenantContext->set($this->managedTenant($tenantId));

            $targetIds = array_keys($perObject);
            $session = new BulkSession(
                actionType: 'multi_attribute_edit',
                targetObjectIds: $targetIds,
                actionPayload: ['pending_change_batch_id' => $batchId->toRfc4122()],
                userId: $approvedBy,
                source: BulkSession::SOURCE_CMD_K_AGENT,
            );
            $sessionId = $session->getId();
            $this->entityManager->persist($session);
            $this->entityManager->flush();

            $this->bulkContext->setBulk(true, $sessionId);
            try {
                [$committedValues, $objectsTouched, $skipped, $issues] = $this->writeChunks(
                    $tenantId,
                    $sessionId,
                    $perObject,
                    $provenanceMeta,
                );
            } finally {
                $this->bulkContext->setBulk(false);
            }

            // Per-chunk clear() detached the session (+ its Tenant proxy).
            $reloaded = $this->entityManager->find(BulkSession::class, $sessionId);
            if ($reloaded instanceof BulkSession) {
                $reloaded->complete($objectsTouched, $skipped, \count($issues));
            }
            $this->entityManager->flush();

            $connection->commit();

            $this->reindexQueue->queueAll($targetIds);

            return new PendingBatchCommitResult(
                bulkSessionId: $sessionId,
                committedValues: $committedValues,
                objectsTouched: $objectsTouched,
                issues: $issues,
            );
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure;
        }
    }

    private function managedTenant(string $tenantId): Tenant
    {
        $tenant = $this->entityManager->getReference(Tenant::class, Uuid::fromString($tenantId));
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Tenant reference vanished mid-commit.');
        }

        return $tenant;
    }

    /**
     * Stream the batch once and index accepted Value changes per object.
     * Only status=accepted rows commit — a race that rejected/expired
     * part of the batch between accept() and here cannot leak rows in.
     *
     * @return array<string, list<array{code: string, before: ?array<string, mixed>, after: array<string, mixed>}>>
     */
    private function collectAcceptedValueChanges(Uuid $batchId): array
    {
        $perObject = [];

        foreach ($this->pendingChanges->iterateBatch($batchId) as $view) {
            if (PendingChangeStatus::Accepted !== $view->status
                || PendingChangeType::Value !== $view->changeType
                || null === $view->targetObjectId
                || null === $view->attributeCode
                || null === $view->after) {
                continue;
            }

            $perObject[$view->targetObjectId->toRfc4122()][] = [
                'code' => $view->attributeCode,
                'before' => $view->before,
                'after' => $view->after,
            ];
        }

        return $perObject;
    }

    /**
     * @param array<string, list<array{code: string, before: ?array<string, mixed>, after: array<string, mixed>}>> $perObject
     * @param array<string, mixed>                                                                                 $provenanceMeta
     *
     * @return array{0: int, 1: int, 2: int, 3: list<array{objectId: string, attributeCode: string, message: string}>}
     */
    private function writeChunks(string $tenantId, Uuid $sessionId, array $perObject, array $provenanceMeta): array
    {
        $committedValues = 0;
        $objectsTouched = 0;
        $skipped = 0;
        $issues = [];

        foreach (array_chunk(array_keys($perObject), self::CHUNK) as $chunkIds) {
            // The previous chunk's clear() detached the Tenant held by
            // TenantContext; re-attach a managed reference so the
            // TenantAssignmentListener can stamp the new ObjectValue rows
            // (import-handler pattern).
            $this->tenantContext->set($this->managedTenant($tenantId));

            // Reloaded per chunk: the previous clear() detached everything.
            $objects = $this->loadObjects($chunkIds);
            $attributes = $this->loadAttributes($perObject, $chunkIds);
            $this->valueWriter->primeChunk(array_values($objects), $attributes);

            foreach ($chunkIds as $objectId) {
                $object = $objects[$objectId] ?? null;
                if (!$object instanceof CatalogObject) {
                    $issues[] = ['objectId' => $objectId, 'attributeCode' => '', 'message' => 'Object no longer exists.'];
                    continue;
                }

                $writes = [];
                foreach ($perObject[$objectId] as $change) {
                    $attribute = $attributes[$change['code']] ?? null;
                    if (!$attribute instanceof Attribute) {
                        $issues[] = ['objectId' => $objectId, 'attributeCode' => $change['code'], 'message' => 'Attribute no longer exists.'];
                        continue;
                    }
                    $writes[] = [
                        'attribute' => $attribute,
                        'envelope' => $change['after'],
                        'locale' => null,
                        'channelId' => null,
                    ];
                }

                $result = $this->valueWriter->writeMany($object, $writes, Provenance::Agent);
                foreach ($result['issues'] as $issue) {
                    $issues[] = ['objectId' => $objectId, 'attributeCode' => $issue['attributeCode'], 'message' => $issue['message']];
                }

                $committedValues += $result['changed'];
                if ($result['changed'] > 0) {
                    ++$objectsTouched;
                    $object->markTouchedByBulkSession($sessionId);
                } else {
                    ++$skipped;
                }

                // Undo-log rows in the multi_attribute_edit shape the
                // rollback handler already understands: message carries
                // the attribute code, old_value the before envelope.
                foreach ($perObject[$objectId] as $change) {
                    $attribute = $attributes[$change['code']] ?? null;
                    if (!$attribute instanceof Attribute) {
                        continue;
                    }
                    $this->entityManager->persist(new BulkLog(
                        $sessionId,
                        $object->getId(),
                        $attribute->getId(),
                        $change['before'],
                        $change['after'],
                        BulkLog::LEVEL_INFO,
                        $change['code'],
                    ));
                }
            }

            $this->entityManager->flush();

            if ([] !== $provenanceMeta) {
                $this->stampProvenanceMeta($tenantId, $chunkIds, $attributes, $provenanceMeta);
            }

            $this->entityManager->clear();

            $this->messageBus->dispatch(new ObjectValuesChangedMessage($chunkIds));
        }

        return [$committedValues, $objectsTouched, $skipped, $issues];
    }

    /**
     * @param list<string> $chunkIds
     *
     * @return array<string, CatalogObject> id => object
     */
    private function loadObjects(array $chunkIds): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('co')
            ->from(CatalogObject::class, 'co')
            ->where('co.id IN (:ids)')
            ->setParameter('ids', $chunkIds)
            ->getQuery()
            ->getResult();

        $objects = [];
        /** @var list<CatalogObject> $rows */
        foreach ($rows as $object) {
            $objects[$object->getId()->toRfc4122()] = $object;
        }

        return $objects;
    }

    /**
     * @param array<string, list<array{code: string, before: ?array<string, mixed>, after: array<string, mixed>}>> $perObject
     * @param list<string>                                                                                         $chunkIds
     *
     * @return array<string, Attribute> code => attribute
     */
    private function loadAttributes(array $perObject, array $chunkIds): array
    {
        $codes = [];
        foreach ($chunkIds as $objectId) {
            foreach ($perObject[$objectId] as $change) {
                $codes[$change['code']] = true;
            }
        }

        if ([] === $codes) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Attribute::class, 'a')
            ->where('a.code IN (:codes)')
            ->setParameter('codes', array_keys($codes))
            ->getQuery()
            ->getResult();

        $attributes = [];
        /** @var list<Attribute> $rows */
        foreach ($rows as $attribute) {
            $attributes[$attribute->getCode()] = $attribute;
        }

        return $attributes;
    }

    /**
     * Stamp the agent provenance shape (jsonb-schemas §5) on the rows the
     * chunk just wrote. BatchValueWriter is shared with the import path
     * and takes no meta, so the stamp is a follow-up UPDATE scoped to the
     * chunk's provenance=agent rows.
     *
     * @param list<string>             $chunkIds
     * @param array<string, Attribute> $attributes
     * @param array<string, mixed>     $provenanceMeta
     */
    private function stampProvenanceMeta(string $tenantId, array $chunkIds, array $attributes, array $provenanceMeta): void
    {
        if ([] === $attributes) {
            return;
        }

        $attributeIds = array_map(
            static fn (Attribute $attribute): string => $attribute->getId()->toRfc4122(),
            array_values($attributes),
        );

        // tenant-safe: explicit tenant_id predicate (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE object_values SET provenance_meta = :meta WHERE tenant_id = :tenant AND object_id IN (:objects) AND attribute_id IN (:attributes) AND provenance = :provenance',
            [
                'meta' => json_encode($provenanceMeta, JSON_THROW_ON_ERROR),
                'tenant' => $tenantId,
                'objects' => $chunkIds,
                'attributes' => $attributeIds,
                'provenance' => Provenance::Agent->value,
            ],
            [
                'objects' => ArrayParameterType::STRING,
                'attributes' => ArrayParameterType::STRING,
            ],
        );
    }
}
