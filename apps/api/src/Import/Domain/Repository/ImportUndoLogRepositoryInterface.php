<?php

declare(strict_types=1);

namespace App\Import\Domain\Repository;

use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Entity\ImportUndoLog;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface ImportUndoLogRepositoryInterface
{
    /** Persist without flushing — the import handler's flushAndClear commits it with the chunk. */
    public function add(ImportUndoLog $log): void;

    /**
     * All undo rows for the session, newest first (rollback replays in reverse
     * of capture order).
     *
     * @return list<ImportUndoLog>
     */
    public function findBySession(ImportSession $session): array;

    /**
     * Undo rows of this session limited to the given objects (#2814).
     *
     * The unbounded {@see findBySession()} loads every row of the run at once —
     * 51 304 of them for a 51 800-row re-import — which exhausted the worker's
     * 256 MiB ceiling in 1.65 seconds. The rollback walks its objects in
     * chunks instead and asks only for the rows it is about to replay.
     *
     * @param list<Uuid> $objectIds
     *
     * @return list<ImportUndoLog>
     */
    public function findBySessionAndObjectIds(ImportSession $session, array $objectIds): array;

    /**
     * Distinct object ids touched by the session's undo rows (the pre-existing
     * objects whose attributes_indexed / completeness / search doc must be
     * rebuilt after a rollback replay).
     *
     * @return list<Uuid>
     */
    public function affectedObjectIds(ImportSession $session): array;

    /**
     * Scope keys (objectId|attributeCode|locale|channelId) of this session's undo
     * rows that a LATER import session has since overwritten on the same cell —
     * rolling back would clobber the newer import, so the caller skips + reports
     * them. Provenance alone cannot distinguish two imports (both `import`).
     *
     * @return array<string, true>
     */
    public function supersededScopeKeys(ImportSession $session): array;

    /**
     * Row counts per {@see \App\Import\Domain\Enum\ImportUndoOperation} value,
     * for the rollback preview.
     *
     * @return array<string, int>
     */
    public function countByOperation(ImportSession $session): array;

    /**
     * Purge undo rows whose session's rollback window has fully closed (no
     * rollback possible any more), tenant-scoped via the caller's context.
     *
     * @return int rows deleted
     */
    public function purgeForClosedWindows(DateTimeImmutable $now, int $limit = 5000): int;
}
