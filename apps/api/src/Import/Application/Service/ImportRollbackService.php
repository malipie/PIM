<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportUndoOperation;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Import\Domain\Repository\ImportUndoLogRepositoryInterface;
use App\Shared\Application\BulkOperationInProgressException;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * IMP2-2.4 — rollback v2. Truthful "Wycofaj import":
 *   1. Replay the undo-log on PRE-EXISTING objects — restore each overwritten
 *      value, delete each value the import newly added — UNLESS the value was
 *      edited by hand after the import (provenance no longer `import`), in which
 *      case it is left and reported as a skip.
 *   2. Delete the objects the import CREATED (stamped with import_session_id, D11).
 *   3. Rebuild attributes_indexed + completeness for the restored objects and
 *      queue Meilisearch: re-index the restored ones, DELETE the created ones
 *      (fixing the v1 ghost-documents bug).
 *   4. Persist a rollback report (counts + manual-edit skips) on the session.
 *
 * Linked Asset rows stay in the DAM (spec §7.7, unchanged from v1). The whole
 * run holds the per-tenant {@see BulkOperationLock} so it never races an import.
 */
final readonly class ImportRollbackService
{
    /**
     * Objects whose undo rows and current values are held in memory at once
     * (#2814).
     *
     * Sized so the resident set stays comparable to one import chunk. The
     * rollback is bounded by objects rather than by undo rows because both
     * loads — undo rows and current values — key off the object.
     */
    private const int ROLLBACK_CHUNK_OBJECTS = 200;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private ImportSessionRepositoryInterface $sessions,
        private ImportUndoLogRepositoryInterface $undoLog,
        private ObjectValueRepositoryInterface $objectValues,
        private AttributesIndexedRebuilder $rebuilder,
        private BulkReindexQueueInterface $reindexQueue,
        private BulkOperationLock $bulkLock,
        private TenantContext $tenantContext,
        private ImportProgressPublisher $progressPublisher,
    ) {
    }

    /**
     * Runs (or resumes) the rollback of a session the caller has already moved
     * to `rolling_back` — see {@see ImportSession::markRollbackStarted()}.
     *
     * #2818 — atomicity was traded for completion, deliberately. The pre-#2818
     * rollback was ONE transaction, which made a failure leave nothing behind;
     * it also meant a full catalogue could not be undone at all, because the
     * work outlived every request budget (over ten minutes for 13 895 objects)
     * and a transaction that long blocks rows for its whole duration. A safety
     * net that cannot run is worth less than one that runs and says how far it
     * got.
     *
     * What replaces atomicity is visibility plus resumability:
     *   - each chunk commits on its own, so progress is durable;
     *   - a checkpoint records how many objects are done, so a redelivered or
     *     re-triggered run continues instead of starting over;
     *   - the session stays `rolling_back` until the last step succeeds, so a
     *     half-undone catalogue is never presented as either untouched or
     *     fully undone.
     *
     * The replay is naturally idempotent, which is what makes resuming safe: a
     * restored value carries its pre-import provenance again, so a second pass
     * treats it as a manual edit and leaves it alone, and objects the import
     * created are deleted by id.
     *
     * @return array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, objectsDone: int, objectsTotal: int, completed: bool, stoppedReason: ?string}
     */
    public function run(ImportSession $session): array
    {
        // One "authorized-at" instant for the entry guard and the final status
        // flip: a window that lapses DURING a long rollback must not strand a
        // session whose data has already been undone.
        $now = new DateTimeImmutable();
        if (!$session->getStatus()->isRollingBack() && !$session->getStatus()->isRollbackable()) {
            throw new LogicException(\sprintf(
                'Import session %s cannot be rolled back (status "%s").',
                $session->getId()->toRfc4122(),
                $session->getStatus()->value,
            ));
        }

        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Import session has no tenant.');
        }
        $this->tenantContext->set($tenant);
        $tenantId = $tenant->getId();

        $targetObjectType = $session->getTargetObjectType();
        if (!$targetObjectType instanceof ObjectType) {
            // Structural imports (attributes / attribute groups) create
            // configuration entities, not CatalogObjects, and are not rolled
            // back through this catalog pipeline.
            throw new LogicException('Structural import sessions cannot be rolled back through the catalog pipeline.');
        }
        $kind = $targetObjectType->getKind();
        $sessionId = $session->getId();

        $lock = $this->bulkLock->acquire($tenant);
        if (null === $lock) {
            // #2818 — PROD-05's own exception rather than a LogicException: the
            // rollback runs in the worker now, and lock contention is a "try
            // again shortly", not a refusal. The handler turns it into a
            // recoverable retry; nothing has been undone yet either way.
            throw new BulkOperationInProgressException($tenant);
        }

        try {
            $totals = $this->totalsFromReport($session);
            // Deterministic order (by object id) is what makes the checkpoint
            // meaningful: "the first N objects are done" only means something if
            // N indexes the same sequence on every run.
            $objectIds = $this->undoLog->affectedObjectIds($session);
            $resumeFrom = 'rollback' === $session->getCheckpointPhase() ? ($session->getCheckpointOffset() ?? 0) : 0;
            // Progress counts BOTH phases. Counting only the undo-log objects
            // reported "0 / 0" for a rollback whose whole job is deleting what
            // the import created — a run that creates rows leaves no undo rows
            // to replay — which is the same "nothing is happening" the operator
            // was shown while an import worked (#2815).
            $objectsTotal = \count($objectIds) + $this->createdObjectCount($session);
            $objectsDone = $resumeFrom;
            $affectedIds = [];

            // --- 1-2. Replay the undo log and rebuild the caches, per chunk ---
            foreach (array_chunk(\array_slice($objectIds, $resumeFrom), self::ROLLBACK_CHUNK_OBJECTS) as $chunkIds) {
                /** @var array{restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, affectedIds: list<string>} $chunkReport */
                $chunkReport = $this->em->wrapInTransaction(
                    fn (): array => $this->replayChunkTransactionally($session, $chunkIds),
                );

                $totals['restoredValues'] += $chunkReport['restoredValues'];
                $totals['removedValues'] += $chunkReport['removedValues'];
                $totals['skippedManualEdits'] += $chunkReport['skippedManualEdits'];
                $totals['skippedSuperseded'] += $chunkReport['skippedSuperseded'];
                $affectedIds = [...$affectedIds, ...$chunkReport['affectedIds']];
                $resumeFrom += \count($chunkIds);
                $objectsDone += \count($chunkIds);

                $lock->refresh();
                $session = $this->persistProgress($sessionId, $totals, $resumeFrom, $objectsDone, $objectsTotal, 'values');
                if ($session->isRollbackCancelRequested()) {
                    return $this->stop($session, $totals, $objectsDone, $objectsTotal, 'cancelled', $affectedIds, $kind);
                }
            }

            // --- 3. Delete the objects the import created (D11) ---
            // tenant-safe: every raw statement is keyed by import_session_id (a
            // tenant-scoped session, loaded owner-scoped) or by ids read from
            // it, and objects/object_values enforce RLS on the app.current_tenant
            // set through $tenantContext above — no cross-tenant reach.
            $createdIds = [];
            while (true) {
                $batch = $this->createdObjectIds($session, self::ROLLBACK_CHUNK_OBJECTS);
                if ([] === $batch) {
                    break;
                }
                /** @var array{objects: int, values: int} $deleted */
                $deleted = $this->em->wrapInTransaction(fn (): array => $this->deleteCreatedBatch($batch));
                $totals['deletedObjects'] += $deleted['objects'];
                $totals['deletedValues'] += $deleted['values'];
                $createdIds = [...$createdIds, ...$batch];
                $objectsDone += \count($batch);

                $lock->refresh();
                $session = $this->persistProgress($sessionId, $totals, $resumeFrom, $objectsDone, $objectsTotal, 'created');
                if ($session->isRollbackCancelRequested()) {
                    return $this->stop($session, $totals, $objectsDone, $objectsTotal, 'cancelled', $affectedIds, $kind, $createdIds);
                }
            }

            // --- 4. Finalize: the status flip is the LAST write, so a session
            // only reads `rolled_back` once every step above has committed. ---
            $this->em->clear();
            $this->tenantContext->set($this->requireTenant($tenantId));
            $final = $this->sessions->findById($sessionId);
            if ($final instanceof ImportSession) {
                $final->markRolledBack($now);
                $final->recordRollbackReport($this->reportPayload($totals, max($objectsDone, $objectsTotal), max($objectsDone, $objectsTotal), 'completed'));
                $final->clearCheckpoint();
                $this->sessions->save($final);
            }

            // --- 5. Meilisearch AFTER the database work is durable ---
            // The index is a derived, idempotent projection (W1-7 ordering:
            // external work runs only once the erasure it reflects is committed).
            $this->reindexQueue->queueAll($affectedIds);
            if ([] !== $createdIds) {
                $this->reindexQueue->queueAllDeleted($createdIds, $kind);
            }

            return $this->outcome($totals, max($objectsDone, $objectsTotal), max($objectsDone, $objectsTotal), true, null);
        } finally {
            $lock->release();
        }
    }

    /**
     * One chunk of the replay, inside the caller's transaction: restore/remove
     * the chunk's values, then rebuild the caches of the objects it touched.
     *
     * @param list<Uuid> $chunkIds
     *
     * @return array{restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, affectedIds: list<string>}
     */
    private function replayChunkTransactionally(ImportSession $session, array $chunkIds): array
    {
        $restored = 0;
        $removed = 0;
        $skipped = 0;
        $skippedSuperseded = 0;
        /** @var array<string, true> $affected */
        $affected = [];

        $this->replayChunk(
            $this->undoLog->findBySessionAndObjectIds($session, $chunkIds),
            $this->currentValueIndex($chunkIds),
            $this->undoLog->supersededScopeKeys($session),
            $affected,
            $restored,
            $removed,
            $skipped,
            $skippedSuperseded,
        );
        $this->em->flush();

        $affectedIds = array_keys($affected);
        $this->rebuildAffected($affectedIds);

        return [
            'restoredValues' => $restored,
            'removedValues' => $removed,
            'skippedManualEdits' => $skipped,
            'skippedSuperseded' => $skippedSuperseded,
            'affectedIds' => $affectedIds,
        ];
    }

    /**
     * Deletes one batch of import-created objects and their values.
     *
     * @param list<string> $ids RFC4122
     *
     * @return array{objects: int, values: int}
     */
    private function deleteCreatedBatch(array $ids): array
    {
        $values = (int) $this->connection->executeStatement(
            'DELETE FROM object_values WHERE object_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
        $objects = (int) $this->connection->executeStatement(
            'DELETE FROM objects WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return ['objects' => $objects, 'values' => $values];
    }

    /**
     * Commits the running totals + checkpoint and returns the reloaded session.
     *
     * Reloading is not incidental: the EntityManager was cleared by the chunk,
     * and the returned session is what the caller reads the cancel flag from —
     * a flag another process wrote while this chunk was running.
     *
     * @param array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int} $totals
     */
    private function persistProgress(Uuid $sessionId, array $totals, int $replayOffset, int $objectsDone, int $objectsTotal, string $phase): ImportSession
    {
        $this->em->clear();
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof ImportSession) {
            throw new LogicException(\sprintf('Import session %s vanished mid-rollback.', $sessionId->toRfc4122()));
        }
        $tenant = $session->getTenant();
        if ($tenant instanceof Tenant) {
            $this->tenantContext->set($tenant);
        }

        // Carry the cancel flag across the write. The report is one JSONB
        // column, so a progress write that rebuilt it from the run's own state
        // would erase a cancellation the operator requested during this chunk —
        // the button would look like it did nothing.
        $session->recordRollbackReport(
            $this->reportPayload($totals, $objectsDone, $objectsTotal, $phase, $session->isRollbackCancelRequested()),
        );
        $session->recordRollbackProgress($objectsDone, $objectsTotal, $phase);
        // The checkpoint indexes the undo-log walk ONLY. Progress counts the
        // deletions too, so feeding it here would make a resumed run skip
        // objects it never replayed.
        $session->recordCheckpoint($replayOffset, 'rollback');
        $this->sessions->save($session);

        $this->progressPublisher->rollbackProgress($session, $objectsDone, $objectsTotal, $phase);

        return $session;
    }

    /**
     * Stops a rollback that will not finish this run, leaving the session in a
     * state that says so — `rolling_back` with a checkpoint, never a status
     * that claims the catalogue is whole either way.
     *
     * @param array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int} $totals
     * @param list<string>                                                                                                                             $affectedIds
     * @param list<string>                                                                                                                             $createdIds
     *
     * @return array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, objectsDone: int, objectsTotal: int, completed: bool, stoppedReason: ?string}
     */
    private function stop(
        ImportSession $session,
        array $totals,
        int $objectsDone,
        int $objectsTotal,
        string $reason,
        array $affectedIds,
        ObjectKind $kind,
        array $createdIds = [],
    ): array {
        $session->recordRollbackStopped($objectsDone, $objectsTotal, $reason);
        $this->sessions->save($session);

        // The work already committed still has to reach the index — the search
        // documents of restored objects are stale otherwise, and a partial
        // rollback with a stale index is the worst of both.
        $this->reindexQueue->queueAll($affectedIds);
        if ([] !== $createdIds) {
            $this->reindexQueue->queueAllDeleted($createdIds, $kind);
        }
        $this->progressPublisher->rollbackStopped($session, $objectsDone, $objectsTotal, $reason);

        return $this->outcome($totals, $objectsDone, $objectsTotal, false, $reason);
    }

    /**
     * Running totals carried across runs. A resumed rollback adds to what the
     * previous attempt committed rather than reporting only its own share.
     *
     * @return array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int}
     */
    private function totalsFromReport(ImportSession $session): array
    {
        $report = $session->getRollbackReport() ?? [];
        $read = static fn (string $key): int => \is_int($report[$key] ?? null) ? $report[$key] : 0;

        return [
            'deletedObjects' => $read('deleted_objects'),
            'deletedValues' => $read('deleted_values'),
            'restoredValues' => $read('restored_values'),
            'removedValues' => $read('removed_values'),
            'skippedManualEdits' => $read('skipped_manual_edits'),
            'skippedSuperseded' => $read('skipped_superseded'),
        ];
    }

    /**
     * @param array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int} $totals
     *
     * @return array<string, mixed>
     */
    private function reportPayload(array $totals, int $objectsDone, int $objectsTotal, string $phase, bool $cancelRequested = false): array
    {
        return [
            'deleted_objects' => $totals['deletedObjects'],
            'deleted_values' => $totals['deletedValues'],
            'restored_values' => $totals['restoredValues'],
            'removed_values' => $totals['removedValues'],
            'skipped_manual_edits' => $totals['skippedManualEdits'],
            'skipped_superseded' => $totals['skippedSuperseded'],
            'objects_done' => $objectsDone,
            'objects_total' => $objectsTotal,
            'phase' => $phase,
            'cancel_requested' => $cancelRequested,
        ];
    }

    /**
     * @param array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int} $totals
     *
     * @return array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, objectsDone: int, objectsTotal: int, completed: bool, stoppedReason: ?string}
     */
    private function outcome(array $totals, int $objectsDone, int $objectsTotal, bool $completed, ?string $stoppedReason): array
    {
        return [
            ...$totals,
            'objectsDone' => $objectsDone,
            'objectsTotal' => $objectsTotal,
            'completed' => $completed,
            'stoppedReason' => $stoppedReason,
        ];
    }

    private function requireTenant(Uuid $tenantId): Tenant
    {
        $tenant = $this->em->find(Tenant::class, $tenantId->toRfc4122());
        if (!$tenant instanceof Tenant) {
            throw new LogicException(\sprintf('Tenant %s vanished mid-rollback.', $tenantId->toRfc4122()));
        }

        return $tenant;
    }

    /**
     * Read-only pre-rollback preview: what rollback WOULD do, without mutating.
     *
     * @return array{created_to_delete: int, values_to_restore: int, values_to_remove: int, manual_edits_to_skip: int, superseded_to_skip: int, rollbackable: bool}
     */
    public function preview(ImportSession $session): array
    {
        $tenant = $session->getTenant();
        if ($tenant instanceof Tenant) {
            $this->tenantContext->set($tenant);
        }

        $createdRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE import_session_id = :sid',
            ['sid' => $session->getId()->toRfc4122()],
        );
        $createdToDelete = (int) (\is_scalar($createdRaw) ? $createdRaw : 0);

        $toRestore = 0;
        $toRemove = 0;
        $manualSkips = 0;
        $supersededSkips = 0;
        $superseded = $this->undoLog->supersededScopeKeys($session);
        // #2814 — the preview walks the same volume as the rollback itself, so
        // it is chunked identically. A dry-run that OOMs is no more useful than
        // a rollback that does.
        foreach (array_chunk($this->undoLog->affectedObjectIds($session), self::ROLLBACK_CHUNK_OBJECTS) as $chunkIds) {
            $index = $this->currentValueIndex($chunkIds);
            foreach ($this->undoLog->findBySessionAndObjectIds($session, $chunkIds) as $row) {
                $code = $row->getAttributeCode();
                if (null === $code) {
                    continue;
                }
                $key = $this->scopeKey(
                    $row->getObjectId()->toRfc4122(),
                    $code,
                    $row->getLocale(),
                    $row->getChannelId()?->toRfc4122(),
                );
                if (isset($superseded[$key])) {
                    ++$supersededSkips;

                    continue;
                }
                $value = $index[$key] ?? null;
                if (null === $value) {
                    continue;
                }
                if (Provenance::Import !== $value->getProvenance()) {
                    ++$manualSkips;

                    continue;
                }
                ImportUndoOperation::ValueOverwritten === $row->getOperation() ? ++$toRestore : ++$toRemove;
            }
            // The preview only counts; detaching per chunk keeps the resident
            // set flat without affecting the caller.
            $this->em->clear();
        }

        return [
            'created_to_delete' => $createdToDelete,
            'values_to_restore' => $toRestore,
            'values_to_remove' => $toRemove,
            'manual_edits_to_skip' => $manualSkips,
            'superseded_to_skip' => $supersededSkips,
            'rollbackable' => $session->getStatus()->isRollbackable() && $session->isWithinRollbackWindow(),
        ];
    }

    /**
     * Rebuilds `attributes_indexed` + completeness for every object the replay
     * restored, in the same chunks the replay itself used (#2814).
     *
     * The replay was chunked first, but this step was not, and it is the one
     * that decides whether the rollback finishes: it reloads every affected
     * object AND every value of every affected object. On the session that
     * motivated the ticket that is 13 895 objects and 51 304 values in one
     * identity map — the exact load the chunked replay had just stopped
     * building. Values are batch-loaded per chunk and handed to the rebuilder,
     * so a chunk costs one query instead of one per object.
     *
     * @param list<string> $affectedIds RFC4122
     */
    private function rebuildAffected(array $affectedIds): void
    {
        foreach (array_chunk($affectedIds, self::ROLLBACK_CHUNK_OBJECTS) as $chunk) {
            $valuesByObject = $this->objectValues->findByObjectIds(
                array_map(static fn (string $id): Uuid => Uuid::fromString($id), $chunk),
            );
            foreach ($chunk as $idRfc) {
                $object = $this->em->find(CatalogObject::class, $idRfc);
                if ($object instanceof CatalogObject) {
                    $this->rebuilder->rebuild($object, $valuesByObject[$idRfc] ?? []);
                }
            }
            $this->em->flush();
            // Same contract as the replay's chunk boundary: clear() detaches the
            // graph without touching the open transaction, so the rollback stays
            // all-or-nothing while the resident set tracks the chunk.
            $this->em->clear();
        }
    }

    /**
     * Current ObjectValues of the given objects, keyed by scope.
     *
     * Takes an explicit id list rather than the whole session (#2814): the
     * session-wide variant loaded every value of every affected object into
     * one index, which is what exhausted the worker.
     *
     * @param list<Uuid> $objectIds
     *
     * @return array<string, \App\Catalog\Domain\Entity\ObjectValue>
     */
    private function currentValueIndex(array $objectIds): array
    {
        $index = [];
        // findByObjectIds returns values grouped by object id (RFC4122 => list).
        foreach ($this->objectValues->findByObjectIds($objectIds) as $values) {
            foreach ($values as $value) {
                $index[$this->scopeKey(
                    $value->getObject()->getId()->toRfc4122(),
                    $value->getAttribute()->getCode(),
                    $value->getLocale(),
                    $value->getChannelId()?->toRfc4122(),
                )] = $value;
            }
        }

        return $index;
    }

    /**
     * Restore/remove each logged value and report the outcome. Two guards keep a
     * rollback from corrupting the catalog:
     *   - manual-edit guard (provenance no longer `import`) — leave + report;
     *   - superseded guard (a LATER import session overwrote the same cell) —
     *     leave + report, never clobber the newer import.
     *
     * The chunk walk that drives this lives in {@see run()}: since #2818 each
     * chunk is its own transaction, so the loop belongs where the checkpoint
     * and the cancel check are.
     */
    /**
     * Replays one chunk's undo rows against the values loaded for it.
     *
     * @param list<\App\Import\Domain\Entity\ImportUndoLog>         $undoRows
     * @param array<string, \App\Catalog\Domain\Entity\ObjectValue> $index
     * @param array<string, true>                                   $superseded
     * @param array<string, true>                                   $affected
     */
    private function replayChunk(
        array $undoRows,
        array $index,
        array $superseded,
        array &$affected,
        int &$restored,
        int &$removed,
        int &$skipped,
        int &$skippedSuperseded,
    ): void {
        foreach ($undoRows as $row) {
            $code = $row->getAttributeCode();
            if (null === $code) {
                continue; // non-value op (object-field/category/relation undo — deferred)
            }
            $key = $this->scopeKey(
                $row->getObjectId()->toRfc4122(),
                $code,
                $row->getLocale(),
                $row->getChannelId()?->toRfc4122(),
            );
            // A later import owns this cell now: restoring would silently revert
            // its write (both carry provenance `import`, so only the undo-log's
            // chronology can tell them apart).
            if (isset($superseded[$key])) {
                ++$skippedSuperseded;

                continue;
            }
            $value = $index[$key] ?? null;
            if (null === $value) {
                continue; // value already gone (e.g. manually deleted) — nothing to do
            }
            // Manual-edit guard: only reverse what the import still owns.
            if (Provenance::Import !== $value->getProvenance()) {
                ++$skipped;

                continue;
            }

            $affected[$row->getObjectId()->toRfc4122()] = true;
            if (ImportUndoOperation::ValueOverwritten === $row->getOperation()) {
                $payload = $row->getPayload();
                /** @var array<string, mixed> $envelope */
                $envelope = \is_array($payload['value'] ?? null) ? $payload['value'] : [];
                $value->updateValue($envelope);
                $before = $payload['provenance'] ?? null;
                if (\is_string($before) && null !== Provenance::tryFrom($before)) {
                    $value->changeProvenance(Provenance::from($before));
                }
                // Restore the FULL before-envelope, including provenance_meta
                // (who/when), so the UI badge reflects the pre-import state.
                /** @var array<string, mixed> $meta */
                $meta = \is_array($payload['provenance_meta'] ?? null) ? $payload['provenance_meta'] : [];
                $value->updateProvenanceMeta($meta);
                ++$restored;
            } elseif (ImportUndoOperation::ValueCreated === $row->getOperation()) {
                $this->em->remove($value);
                ++$removed;
            }
        }
    }

    /**
     * @return list<string> RFC4122 ids of objects the import created, capped at $limit
     */
    private function createdObjectIds(ImportSession $session, int $limit): array
    {
        // #2818 — one batch at a time, ordered, so deletion is chunked like the
        // replay and a resumed run simply asks again: whatever is still there
        // is whatever is still to delete.
        /** @var list<array{id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT id FROM objects WHERE import_session_id = :sid ORDER BY id LIMIT %d', max(1, $limit)),
            ['sid' => $session->getId()->toRfc4122()],
        );

        return array_map(static fn (array $r): string => $r['id'], $rows);
    }

    /** #2818 — how many objects the import created and the rollback has still to delete. */
    private function createdObjectCount(ImportSession $session): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE import_session_id = :sid',
            ['sid' => $session->getId()->toRfc4122()],
        );

        return (int) (\is_scalar($count) ? $count : 0);
    }

    private function scopeKey(string $objectId, string $attributeCode, ?string $locale, ?string $channelId): string
    {
        return $objectId.'|'.$attributeCode.'|'.($locale ?? '').'|'.($channelId ?? '');
    }
}
