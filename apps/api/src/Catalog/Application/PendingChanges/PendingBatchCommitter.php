<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\BatchValueWriter;
use App\Catalog\Application\Bulk\BulkAddCategoryHandler;
use App\Catalog\Application\Bulk\BulkChangeStatusHandler;
use App\Catalog\Application\Bulk\BulkMoveCategoryHandler;
use App\Catalog\Application\Bulk\BulkRemoveCategoryHandler;
use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Command\CreateCatalogObject\CreateCatalogObjectCommand;
use App\Catalog\Application\Command\CreateCatalogObject\CreateCatalogObjectHandler;
use App\Catalog\Application\Handler\RebuildAttributesIndexedHandler;
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
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\ActingUserContextInterface;
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
 * MVP: all-or-nothing (no partial accept).
 *
 * AICG-P3-01 (#2334) — value rows carry their scope: a pending change
 * materialized with scope_locale / scope_channel commits to that exact
 * ObjectValue row (BatchValueWriter routes through the same
 * localizable/scopable rules as manual edits). Bulk-edit batches keep
 * materializing global rows, so their behaviour is unchanged.
 */
final readonly class PendingBatchCommitter implements PendingBatchCommitPort
{
    private const int CHUNK = 200;

    /**
     * #3053 — batches at or below this many objects rebuild `attributes_indexed`
     * INLINE instead of handing it to the worker.
     *
     * The async rebuild is the import pattern: right for 50k rows, wrong for an
     * approval the operator is watching. `object_values` commits synchronously,
     * but the product detail endpoint reads the denormalised projection — so the
     * refetch fired right after `approve` returns races the worker and shows the
     * value from BEFORE the change. The operator sees an unchanged field and has
     * to leave the product and come back.
     *
     * Invisible in dev and in tests: there `async` is a `sync://` alias
     * (messenger.yaml), so the rebuild is already in-band. Two earlier attempts
     * at this bug hardened the editor instead, because the editor is where the
     * symptom shows.
     */
    private const int INLINE_REBUILD_MAX_OBJECTS = 25;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private PendingChangesPort $pendingChanges,
        private BatchValueWriter $valueWriter,
        private BulkContext $bulkContext,
        private BulkReindexQueueInterface $reindexQueue,
        private MessageBusInterface $messageBus,
        private RebuildAttributesIndexedHandler $rebuildIndexed,
        private BulkAddCategoryHandler $addCategories,
        private BulkRemoveCategoryHandler $removeCategories,
        private BulkMoveCategoryHandler $moveCategories,
        private BulkChangeStatusHandler $changeStatuses,
        private CreateCatalogObjectHandler $createObjects,
        private ActingUserContextInterface $actingUser,
        private ChannelResolverInterface $channelResolver,
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

            [$perObject, $categoryBatch, $objectBatch, $statusBatch] = $this->collectAcceptedChanges($batchId);
            $families = array_filter([
                [] !== $perObject,
                null !== $categoryBatch,
                [] !== $objectBatch,
                null !== $statusBatch,
            ]);
            if (\count($families) > 1) {
                throw new LogicException('Mixed pending-change families are not supported - materializers emit homogeneous batches.');
            }
            if ([] === $families) {
                $connection->rollBack();

                return PendingBatchCommitResult::nothingToCommit();
            }

            if (null !== $categoryBatch) {
                $result = $this->commitCategoryBatch($tenantId, $batchId, $approvedBy, $categoryBatch);
                $connection->commit();

                return $result;
            }

            if ([] !== $objectBatch) {
                $result = $this->commitObjectBatch($tenantId, $batchId, $approvedBy, $objectBatch);
                $connection->commit();

                return $result;
            }

            if (null !== $statusBatch) {
                $result = $this->commitStatusBatch($tenantId, $batchId, $approvedBy, $statusBatch);
                $connection->commit();

                return $result;
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

            // #3053 — AFTER the commit, so the rebuild reads the values it is
            // supposed to project. This is what makes the new value visible to
            // the refetch the frontend fires the moment `approve` returns,
            // instead of losing that race to the worker.
            //
            // The queued message above is deliberately LEFT IN PLACE: the
            // in-transaction dispatch is part of the choreography the rollback
            // path depends on (AgentRollbackTest), and the rebuild is
            // idempotent — it recomputes from canonical object_values — so the
            // worker's later pass is a cheap no-op rather than a second source
            // of truth. Bounded to small batches so an import-scale commit
            // never pays for it on the request thread.
            if ([] !== $targetIds && \count($targetIds) <= self::INLINE_REBUILD_MAX_OBJECTS) {
                $this->rebuildIndexed->__invoke(new ObjectValuesChangedMessage(
                    $targetIds,
                    Uuid::fromString($tenantId),
                ));
            }

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

    /**
     * AICG-P3-01 — scoped proposals persist the channel CODE (bare
     * cross-BC data, ADR-0015); the write path needs the id. Resolved at
     * commit time so a channel deleted between materialize and approve
     * surfaces as an issue, not a broken row.
     */
    private function resolveChannelId(string $code, string $tenantId): ?Uuid
    {
        return $this->channelResolver->resolveId($code, $this->managedTenant($tenantId));
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
     * Stream the batch once and index accepted changes: Value rows per
     * object, Category rows as one homogeneous batch (operation +
     * category ids are identical across the batch by construction —
     * AssignCategoriesMaterializer emits them that way). Only
     * status=accepted rows commit — a race that rejected/expired part of
     * the batch between accept() and here cannot leak rows in.
     *
     * @return array{
     *   0: array<string, list<array{code: string, before: ?array<string, mixed>, after: array<string, mixed>, locale: ?string, channel: ?string}>>,
     *   1: ?array{operation: string, categoryIds: list<string>, objectIds: list<string>},
     *   2: list<array<string, mixed>>,
     *   3: ?array{transition: string, objectIds: list<string>}
     * }
     */
    private function collectAcceptedChanges(Uuid $batchId): array
    {
        $perObject = [];
        $categoryBatch = null;
        $objectBatch = [];
        $statusBatch = null;

        foreach ($this->pendingChanges->iterateBatch($batchId) as $view) {
            if (PendingChangeStatus::Accepted !== $view->status) {
                continue;
            }

            if (PendingChangeType::Value === $view->changeType && null !== $view->targetObjectId && null !== $view->attributeCode && null !== $view->after) {
                $perObject[$view->targetObjectId->toRfc4122()][] = [
                    'code' => $view->attributeCode,
                    'before' => $view->before,
                    'after' => $view->after,
                    // AICG-P3-01 — scoped proposals commit to their exact row.
                    'locale' => $view->scopeLocale,
                    'channel' => $view->scopeChannel,
                ];
                continue;
            }

            if (PendingChangeType::Category === $view->changeType && null !== $view->targetObjectId && null !== $view->after) {
                $operation = $view->after['operation'] ?? null;
                $categoryIds = $view->after['category_ids'] ?? null;
                if (!\is_string($operation) || !\is_array($categoryIds)) {
                    continue;
                }
                if (null === $categoryBatch) {
                    $ids = [];
                    foreach ($categoryIds as $id) {
                        if (\is_string($id)) {
                            $ids[] = $id;
                        }
                    }
                    $categoryBatch = ['operation' => $operation, 'categoryIds' => $ids, 'objectIds' => []];
                }
                $categoryBatch['objectIds'][] = $view->targetObjectId->toRfc4122();
                continue;
            }

            if (PendingChangeType::Object === $view->changeType && null !== $view->after) {
                $objectBatch[] = $view->after;
                continue;
            }

            if (PendingChangeType::Status === $view->changeType && null !== $view->targetObjectId && null !== $view->after) {
                $transition = $view->after['transition'] ?? null;
                if (!\is_string($transition) || '' === $transition) {
                    continue;
                }
                if (null === $statusBatch) {
                    $statusBatch = ['transition' => $transition, 'objectIds' => []];
                } elseif ($statusBatch['transition'] !== $transition) {
                    throw new LogicException('One status batch cannot contain different workflow transitions.');
                }
                $statusBatch['objectIds'][] = $view->targetObjectId->toRfc4122();
            }
        }

        return [$perObject, $categoryBatch, $objectBatch, $statusBatch];
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function commitObjectBatch(string $tenantId, Uuid $batchId, Uuid $approvedBy, array $batch): PendingBatchCommitResult
    {
        $this->tenantContext->set($this->managedTenant($tenantId));
        $createdIds = [];
        $createdKinds = [];
        $initialValueCount = 0;

        foreach ($batch as $payload) {
            $objectTypeId = $payload['object_type_id'] ?? null;
            $code = $payload['code'] ?? null;
            $kind = $payload['kind'] ?? null;
            $attributes = $payload['attributes'] ?? [];
            if (!\is_string($objectTypeId) || !Uuid::isValid($objectTypeId)
                || !\is_string($code) || !\is_string($kind)
                || !\is_array($attributes)) {
                throw new LogicException('Malformed object-creation pending change.');
            }
            $expectedKind = ObjectKind::tryFrom($kind);
            if (!$expectedKind instanceof ObjectKind) {
                throw new LogicException(\sprintf('Unknown object kind "%s".', $kind));
            }

            $parentId = $this->optionalUuid($payload['parent_id'] ?? null);
            $categoryIds = $this->uuidList($payload['category_ids'] ?? []);
            $primaryCategoryId = $this->optionalUuid($payload['primary_category_id'] ?? null);
            /** @var array<string, mixed> $attributes */
            $createdId = ($this->createObjects)(new CreateCatalogObjectCommand(
                objectTypeId: Uuid::fromString($objectTypeId),
                code: $code,
                expectedKind: $expectedKind,
                parentId: $parentId,
                attributes: $attributes,
                categoryIds: [] === $categoryIds ? null : $categoryIds,
                primaryCategoryId: $primaryCategoryId,
                provenance: Provenance::Agent,
            ));
            $createdIds[] = $createdId->toRfc4122();
            $createdKinds[$expectedKind->value][] = $createdId->toRfc4122();
            $initialValueCount += \count($attributes);
        }

        $session = new BulkSession(
            actionType: 'create_object',
            targetObjectIds: $createdIds,
            actionPayload: [
                'pending_change_batch_id' => $batchId->toRfc4122(),
                'created_kinds' => $createdKinds,
            ],
            userId: $approvedBy,
            source: BulkSession::SOURCE_CMD_K_AGENT,
        );
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        foreach ($createdIds as $createdId) {
            $object = $this->entityManager->find(CatalogObject::class, $createdId);
            $version = $object instanceof CatalogObject ? $object->getVersion() : null;
            $this->entityManager->persist(new BulkLog(
                $session->getId(),
                Uuid::fromString($createdId),
                oldValue: null,
                newValue: ['created_version' => $version],
                message: 'create_object',
            ));
        }
        $session->complete(\count($createdIds), 0, 0);
        $this->entityManager->flush();
        $this->reindexQueue->queueAll($createdIds);

        return new PendingBatchCommitResult($session->getId(), $initialValueCount, \count($createdIds), []);
    }

    /** @param array{transition: string, objectIds: list<string>} $batch */
    private function commitStatusBatch(string $tenantId, Uuid $batchId, Uuid $approvedBy, array $batch): PendingBatchCommitResult
    {
        $this->tenantContext->set($this->managedTenant($tenantId));
        $session = new BulkSession(
            actionType: 'change_status',
            targetObjectIds: $batch['objectIds'],
            actionPayload: [
                'transition' => $batch['transition'],
                'pending_change_batch_id' => $batchId->toRfc4122(),
            ],
            userId: $approvedBy,
            source: BulkSession::SOURCE_CMD_K_AGENT,
        );
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->actingUser->set($approvedBy);
        try {
            $counts = $this->changeStatuses->handle($session, $batch['transition'], null);
        } finally {
            $this->actingUser->set(null);
        }

        $issues = $counts['skipped'] > 0
            ? [['objectId' => '', 'attributeCode' => '', 'message' => \sprintf('%d object(s) were blocked when the workflow guard was re-checked at commit.', $counts['skipped'])]]
            : [];

        return new PendingBatchCommitResult(
            bulkSessionId: $session->getId(),
            committedValues: $counts['success'],
            objectsTouched: $counts['success'],
            issues: $issues,
        );
    }

    private function optionalUuid(mixed $value): ?Uuid
    {
        return \is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }

    /** @return list<Uuid> */
    private function uuidList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }
        $ids = [];
        foreach ($values as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                $ids[] = Uuid::fromString($value);
            }
        }

        return $ids;
    }

    /**
     * AGENT-P3-05 (#1965) — commit a category batch through the EXISTING
     * bulk category handlers: they own the junction writes, the undo-log
     * rows the 24h rollback replays, the session completion and the
     * Meilisearch reindex. The agent path only supplies the BulkSession
     * (source=cmd_k_agent) so the run's bulk_operation_id points at it.
     *
     * @param array{operation: string, categoryIds: list<string>, objectIds: list<string>} $batch
     */
    private function commitCategoryBatch(string $tenantId, Uuid $batchId, Uuid $approvedBy, array $batch): PendingBatchCommitResult
    {
        $this->tenantContext->set($this->managedTenant($tenantId));

        $actionType = match ($batch['operation']) {
            'add' => 'add_category',
            'remove' => 'remove_category',
            'move' => 'move_category',
            default => throw new LogicException(\sprintf('Unknown category operation "%s".', $batch['operation'])),
        };

        $session = new BulkSession(
            actionType: $actionType,
            targetObjectIds: $batch['objectIds'],
            actionPayload: [
                'category_ids' => $batch['categoryIds'],
                'pending_change_batch_id' => $batchId->toRfc4122(),
            ],
            userId: $approvedBy,
            source: BulkSession::SOURCE_CMD_K_AGENT,
        );
        $sessionId = $session->getId();
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $counts = match ($actionType) {
            'add_category' => $this->addCategories->handle($session, $batch['categoryIds']),
            'remove_category' => $this->removeCategories->handle($session, $batch['categoryIds']),
            default => $this->moveCategories->handle($session, $batch['categoryIds']),
        };

        return new PendingBatchCommitResult(
            bulkSessionId: $sessionId,
            committedValues: $counts['success'],
            objectsTouched: $counts['success'],
            issues: [],
        );
    }

    /**
     * @param array<string, list<array{code: string, before: ?array<string, mixed>, after: array<string, mixed>, locale: ?string, channel: ?string}>> $perObject
     * @param array<string, mixed>                                                                                                                    $provenanceMeta
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
                    $channelCode = $change['channel'] ?? null;
                    $channelId = null;
                    if (\is_string($channelCode) && '' !== $channelCode) {
                        $channelId = $this->resolveChannelId($channelCode, $tenantId);
                        if (null === $channelId) {
                            $issues[] = ['objectId' => $objectId, 'attributeCode' => $change['code'], 'message' => \sprintf('Channel "%s" no longer exists.', $channelCode)];
                            continue;
                        }
                    }
                    $writes[] = [
                        'attribute' => $attribute,
                        'envelope' => $change['after'],
                        'locale' => \is_string($change['locale'] ?? null) ? $change['locale'] : null,
                        'channelId' => $channelId,
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

            // #2980 production regression: async workers have no HTTP
            // principal to recover the tenant from. Carry it on the message
            // itself so the worker can bind TenantContext + the RLS GUC before
            // rebuilding attributes_indexed from the canonical values.
            $this->messageBus->dispatch(new ObjectValuesChangedMessage(
                $chunkIds,
                Uuid::fromString($tenantId),
            ));
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
     * @param array<string, list<array{code: string, before: ?array<string, mixed>, after: array<string, mixed>, locale: ?string, channel: ?string}>> $perObject
     * @param list<string>                                                                                                                            $chunkIds
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
