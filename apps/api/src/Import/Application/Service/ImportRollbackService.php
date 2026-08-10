<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportUndoOperation;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Import\Domain\Repository\ImportUndoLogRepositoryInterface;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

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
    ) {
    }

    /**
     * @return array{deletedObjects: int, deletedValues: int, restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int}
     */
    public function rollback(ImportSession $session): array
    {
        // One "authorized-at" instant for both the entry guard and the final
        // status flip: a window that lapses DURING the (sub-second) rollback work
        // must not abort markRolledBack() after the data was already mutated.
        $now = new DateTimeImmutable();
        // Fail fast BEFORE touching data if the window closed / status is not
        // rollbackable — mirrors markRolledBack()'s guards without mutating yet.
        if (!$session->getStatus()->isRollbackable() || !$session->isWithinRollbackWindow($now)) {
            throw new LogicException(\sprintf(
                'Import session %s cannot be rolled back (status "%s" or window expired).',
                $session->getId()->toRfc4122(),
                $session->getStatus()->value,
            ));
        }

        $tenant = $session->getTenant();
        if ($tenant instanceof Tenant) {
            $this->tenantContext->set($tenant);
        }
        $lock = $this->bulkLock->acquire($tenant instanceof Tenant ? $tenant : throw new LogicException('Import session has no tenant.'));
        if (null === $lock) {
            throw new LogicException('Another bulk operation is in progress for this tenant; retry shortly.');
        }

        try {
            $targetObjectType = $session->getTargetObjectType();
            if (!$targetObjectType instanceof ObjectType) {
                // Structural imports (attributes / attribute groups) create
                // configuration entities, not CatalogObjects, and are not
                // rolled back through this catalog pipeline.
                throw new LogicException('Structural import sessions cannot be rolled back through the catalog pipeline.');
            }
            $kind = $targetObjectType->getKind();

            // AUD-040 (W2-5) — the four DB steps (replay overwrites, rebuild the
            // restored caches, delete the created objects/values, flip the
            // session status) run as ONE transaction. The v2 shape committed
            // each step independently, so a worker crash (FrankenPHP restart,
            // OOM, deploy) between any two left the catalog half-rolled-back:
            // orphan objects, or data deleted while the session still read
            // `success` → a second rollback replaying a spent undo-log. Wrapping
            // them makes the rollback ALL-OR-NOTHING — a crash reverts every
            // mutation AND leaves the status untouched, so the retry runs on an
            // intact undo-log (no double-apply). The lock holds for the whole
            // run, so concurrency never widens this to a long-lived lock.
            //
            // wrapInTransaction() and $this->connection share the one DBAL
            // connection Symfony autowires, so the raw DELETEs below join the
            // same transaction as the ORM flushes (same pattern as
            // GenerateVariantsController / ObjectRelationService).
            /** @var array{deletedObjects: int, deletedValues: int, createdIds: list<string>, affectedIds: list<string>, report: array{restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, affectedIds: list<string>}} $outcome */
            $outcome = $this->em->wrapInTransaction(function () use ($session, $now): array {
                // --- 1. Replay the value undo-log on pre-existing objects ---
                $report = $this->replayUndoLog($session);

                // --- 2. Rebuild attributes_indexed + completeness for restored objects ---
                $affectedIds = $report['affectedIds'];
                foreach ($affectedIds as $idRfc) {
                    $object = $this->em->find(CatalogObject::class, $idRfc);
                    if ($object instanceof CatalogObject) {
                        $this->rebuilder->rebuild($object);
                    }
                }
                if ([] !== $affectedIds) {
                    $this->em->flush();
                }

                // --- 3. Delete the objects the import created (D11), capture for Meili ---
                // tenant-safe: every raw DELETE/SELECT below is keyed by
                // import_session_id (a tenant-scoped session, loaded owner-scoped),
                // and objects/object_values enforce RLS on app.current_tenant set
                // via $tenantContext above — no cross-tenant reach.
                $createdIds = $this->createdObjectIds($session);
                $deletedValues = 0;
                $deletedObjects = 0;
                if ([] !== $createdIds) {
                    $deletedValues = (int) $this->connection->executeStatement(
                        'DELETE FROM object_values WHERE object_id IN (SELECT id FROM objects WHERE import_session_id = :sid)',
                        ['sid' => $session->getId()->toRfc4122()],
                    );
                    $deletedObjects = (int) $this->connection->executeStatement(
                        'DELETE FROM objects WHERE import_session_id = :sid',
                        ['sid' => $session->getId()->toRfc4122()],
                    );
                }

                // --- 4. Finalize: flip status + persist the report ---
                // The raw DELETEs ran outside the ORM unit of work; clear +
                // reload so the session mutation flushes against a clean
                // Identity Map (clear() detaches in-memory entities only — it
                // does not touch the open transaction). markRolledBack() is the
                // LAST write to flush, so the status flip commits atomically
                // with the deletes above.
                $this->em->clear();
                $reload = $this->sessions->findById($session->getId());
                if ($reload instanceof ImportSession) {
                    $reload->markRolledBack($now);
                    $reload->recordRollbackReport([
                        'deleted_objects' => $deletedObjects,
                        'deleted_values' => $deletedValues,
                        'restored_values' => $report['restoredValues'],
                        'removed_values' => $report['removedValues'],
                        'skipped_manual_edits' => $report['skippedManualEdits'],
                        'skipped_superseded' => $report['skippedSuperseded'],
                    ]);
                    $this->sessions->save($reload);
                }

                return [
                    'deletedObjects' => $deletedObjects,
                    'deletedValues' => $deletedValues,
                    'createdIds' => $createdIds,
                    'affectedIds' => $affectedIds,
                    'report' => $report,
                ];
            });

            // --- 5. Meilisearch AFTER the DB transaction commits ---
            // DB is the source of truth; the search index is a derived,
            // idempotent projection (W1-7 ordering: external work runs only once
            // the DB erasure is durable). Queueing inside the transaction would
            // schedule ghost re-index/delete ops for a rollback that then rolled
            // back. The queue is a request-scoped collector flushed in one
            // batched call on kernel.terminate, so this stays a single Meili
            // round-trip. The v1 ghost-documents fix (drop the created docs)
            // rides along here, post-commit.
            $this->reindexQueue->queueAll($outcome['affectedIds']);
            if ([] !== $outcome['createdIds']) {
                $this->reindexQueue->queueAllDeleted($outcome['createdIds'], $kind);
            }

            return [
                'deletedObjects' => $outcome['deletedObjects'],
                'deletedValues' => $outcome['deletedValues'],
                'restoredValues' => $outcome['report']['restoredValues'],
                'removedValues' => $outcome['report']['removedValues'],
                'skippedManualEdits' => $outcome['report']['skippedManualEdits'],
                'skippedSuperseded' => $outcome['report']['skippedSuperseded'],
            ];
        } finally {
            $lock->release();
        }
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
     * Current ObjectValues of the given objects, keyed by scope.
     *
     * Takes an explicit id list rather than the whole session (#2814): the
     * session-wide variant loaded every value of every affected object into
     * one index, which is what exhausted the worker.
     *
     * @param list<\Symfony\Component\Uid\Uuid> $objectIds
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
     * @return array{restoredValues: int, removedValues: int, skippedManualEdits: int, skippedSuperseded: int, affectedIds: list<string>}
     */
    private function replayUndoLog(ImportSession $session): array
    {
        // #2814 — walk the session's objects in chunks. Loading every undo row
        // and every current value up front cost 256 MiB in 1.65 s on a
        // 51 800-row re-import (51 304 undo rows), so the rollback died before
        // it could undo anything — the safety net failing in exactly the
        // situation it exists for.
        //
        // Chunking stays inside the caller's transaction: clear() detaches the
        // in-memory graph without committing, so a failure mid-way still rolls
        // the whole rollback back. All-or-nothing is preserved; only the
        // resident set shrinks.
        $superseded = $this->undoLog->supersededScopeKeys($session);
        $restored = 0;
        $removed = 0;
        $skipped = 0;
        $skippedSuperseded = 0;
        /** @var array<string, true> $affected */
        $affected = [];

        foreach (array_chunk($this->undoLog->affectedObjectIds($session), self::ROLLBACK_CHUNK_OBJECTS) as $objectIds) {
            $undoRows = $this->undoLog->findBySessionAndObjectIds($session, $objectIds);
            // Current values of THIS chunk's objects, keyed by
            // (objectId|attributeCode|locale|channel) so each undo row maps directly.
            $index = $this->currentValueIndex($objectIds);

            $this->replayChunk(
                $undoRows,
                $index,
                $superseded,
                $affected,
                $restored,
                $removed,
                $skipped,
                $skippedSuperseded,
            );

            $this->em->flush();
            $this->em->clear();
        }

        return [
            'restoredValues' => $restored,
            'removedValues' => $removed,
            'skippedManualEdits' => $skipped,
            'skippedSuperseded' => $skippedSuperseded,
            'affectedIds' => array_keys($affected),
        ];
    }

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
     * @return list<string> RFC4122 ids of objects the import created
     */
    private function createdObjectIds(ImportSession $session): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id FROM objects WHERE import_session_id = :sid',
            ['sid' => $session->getId()->toRfc4122()],
        );

        return array_map(static fn (array $r): string => $r['id'], $rows);
    }

    private function scopeKey(string $objectId, string $attributeCode, ?string $locale, ?string $channelId): string
    {
        return $objectId.'|'.$attributeCode.'|'.($locale ?? '').'|'.($channelId ?? '');
    }
}
