<?php

declare(strict_types=1);

namespace App\Import\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P5-01 (#1970) — commit an APPROVED schema batch (UC1 "IdoSell
 * schema in 5 minutes"): accepted pending rows of type=schema carry
 * structural-import cells (the same header=>value shape the CSV/XLSX
 * structural import consumes), and the adapter replays them through
 * the EXISTING structural creators (AttributeGroupImportCreator /
 * AttributeImportCreator -> Catalog CQRS commands), groups before
 * attributes, under the per-tenant BulkOperationLock, atomically.
 *
 * Idempotency mirrors the value path: the accept transition only moves
 * status=pending rows, so a second commit finds nothing and no-ops
 * (committed=false). Schema runs have no BulkSession — the pending
 * batch id itself is the operation handle (P5-04 rollback reads the
 * created codes back from the batch).
 */
interface SchemaImportPort
{
    public function commitSchemaBatch(Uuid $batchId, Uuid $approvedBy): SchemaCommitResult;

    /**
     * AGENT-P5-04 (#1973) — "Cofnij tę operację" for schema: delete
     * ONLY what the batch CREATED (the commit annotates each row's
     * outcome) and ONLY when dataless — an attribute with values or a
     * group with foreign attachments blocks the WHOLE rollback
     * (all-or-nothing, nothing partially deleted) with an
     * operator-facing reason.
     */
    public function rollbackSchemaBatch(Uuid $batchId): SchemaRollbackResult;
}
