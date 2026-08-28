<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\BulkGuard;
use App\Catalog\Contracts\Service\BulkOperationScope;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Toggle that bulk handlers flip ON to opt the synchronous Doctrine
 * listeners ({@see \App\Catalog\Infrastructure\Doctrine\EventListener\AttributesIndexedSyncListener},
 * {@see \App\Catalog\Infrastructure\Doctrine\EventListener\CompletenessRecalculator})
 * out of every persist + flush cycle.
 *
 * Per CLAUDE.md "Domain modeling" + lessons from #13: synchronous
 * listeners running per-row turn a 50k SKU bulk import into a 50k×listener
 * walk that detaches the catalog. Bulk workers set the flag, do their
 * persist + flush cycle in batches, and dispatch a `ObjectValuesChangedMessage`
 * for the asynchronous `RebuildAttributesIndexedHandler` which rebuilds
 * `attributes_indexed` + `completeness_pct` once per affected object.
 *
 * Default state is `false` — single-edit flows (admin UI, REST API)
 * leave the listeners on. The flag is request-scoped (Symfony service
 * is request-shared by default), so a misbehaving bulk run cannot leak
 * into a follow-up admin request.
 *
 * VIEW-35 (#575) — additionally carries the active `bulkSessionId` so
 * downstream listeners on `ObjectValue` can stamp
 * `provenance = Bulk` + `meta.bulk_session_id` for audit (PRD §5.4).
 */
final class BulkContext implements ResetInterface, BulkGuard, BulkOperationScope
{
    private bool $bulk = false;
    private ?Uuid $sessionId = null;

    public function setBulk(bool $bulk, ?Uuid $sessionId = null): void
    {
        $this->bulk = $bulk;
        $this->sessionId = $bulk ? $sessionId : null;
    }

    public function isBulk(): bool
    {
        return $this->bulk;
    }

    public function getSessionId(): ?Uuid
    {
        return $this->sessionId;
    }

    public function run(callable $operation): mixed
    {
        if ($this->bulk) {
            return $operation();
        }

        $this->setBulk(true);
        try {
            return $operation();
        } finally {
            $this->setBulk(false);
        }
    }

    /**
     * Worker-mode safety: the singleton BulkContext gets reset between
     * requests so an early-terminated bulk job does not leave the flag on
     * for the next caller's listeners.
     */
    public function reset(): void
    {
        $this->bulk = false;
        $this->sessionId = null;
    }
}
