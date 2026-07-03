<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-04 (#1964) — "Cofnij tę operację": roll back a committed
 * agent batch by its BulkSession id (agent_runs.bulk_operation_id).
 *
 * The replay is value-canonical: object_values rows are restored from
 * the bulk_logs undo capture (a value that did not exist before is
 * removed, an overwritten one gets its old envelope back), then the
 * attributes_indexed projection + Meilisearch rebuild from the restored
 * canonical rows. A row whose current value no longer matches what the
 * agent wrote was superseded by a later (manual) edit and is SKIPPED —
 * rollback never clobbers newer human work.
 *
 * Returns the number of restored values. Inherits the BulkSession
 * guards: 24h window, single use.
 */
interface BulkRollbackPort
{
    public function rollbackSession(Uuid $bulkSessionId): int;
}
