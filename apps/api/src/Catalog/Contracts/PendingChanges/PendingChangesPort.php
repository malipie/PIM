<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\PendingChanges;

use Symfony\Component\Uid\Uuid;

/**
 * Single approval gate over the `pending_changes` table (ADR-0024 c).
 *
 * This port is the ONLY way producers (the agent BC, future integrations)
 * touch pending changes. The materialized rows ARE the plan shown to the
 * operator (plan == diff); nothing reaches the catalog before an accept.
 *
 * The batch id groups all proposals of one producer run; MVP approval is
 * all-or-nothing per batch. Accept/reject/expire only transition rows
 * that are still `pending` (idempotent — a second call affects 0 rows).
 */
interface PendingChangesPort
{
    /**
     * Persist proposals for a batch. Memory-safe for large batches
     * (chunked flush+clear). Returns the number of rows materialized.
     *
     * @param string                       $provenance coarse producer classifier (e.g. 'agent')
     * @param iterable<PendingChangeDraft> $drafts
     */
    public function materialize(Uuid $batchId, string $provenance, iterable $drafts): int;

    /**
     * @return list<PendingChangeView>
     */
    public function listBatch(Uuid $batchId, int $limit = 100, int $offset = 0): array;

    /**
     * Stream the whole batch without hydrating it at once.
     *
     * @return iterable<PendingChangeView>
     */
    public function iterateBatch(Uuid $batchId): iterable;

    public function countBatch(Uuid $batchId): int;

    /**
     * @return int rows transitioned pending -> accepted
     */
    public function accept(Uuid $batchId): int;

    /**
     * @return int rows transitioned pending -> rejected
     */
    public function reject(Uuid $batchId): int;

    /**
     * @return int rows transitioned pending -> expired
     */
    public function expire(Uuid $batchId): int;
}
