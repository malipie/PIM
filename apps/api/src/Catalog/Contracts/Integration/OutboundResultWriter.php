<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Integration;

use Symfony\Component\Uid\Uuid;

/**
 * Write seam for outbound-sync result capture (#2636, ADR-0022).
 *
 * After a successful push, the Integration sync stores the remote record id
 * (extracted from the write response) into an ordinary PIM attribute with
 * `provenance = integration`. Subsequent pushes then inject it through a
 * regular outbound field mapping, turning creates into updates — no dedicated
 * pairing table and nothing vendor-specific.
 */
interface OutboundResultWriter
{
    /**
     * Writes `$value` into `$attributeCode` of the object; returns true when
     * the value was written (i.e. it changed), false on a no-op (same value)
     * or when the object/attribute cannot be resolved. Persists into the unit
     * of work without flushing — the sync runner owns the flush cycle.
     */
    public function writeValue(Uuid $objectId, string $attributeCode, string $value): bool;
}
