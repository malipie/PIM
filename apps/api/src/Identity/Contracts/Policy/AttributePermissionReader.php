<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

use Symfony\Component\Uid\Uuid;

/**
 * ULV-04b (#986) — cross-context contract for the 3-state per-attribute
 * permission resolution (PRD §3.5).
 *
 * The full {@see \App\Identity\Application\Policy\AttributePermissionPolicy}
 * resolver lives in `Identity_Internals` and cannot be imported by
 * Catalog (Deptrac). This contract exposes the minimum surface other
 * bundles need — answering "can the **current** user view / edit this
 * attribute id" — so the universal list schema (ULV-03) and any future
 * field-level filtering can gate columns and serialized values without
 * leaking the policy chain.
 *
 * The current user / tenant comes from the Symfony security token via
 * the adapter; callers do not pass the user explicitly. That keeps the
 * surface narrow and the contract testable with a simple fake.
 *
 * Implementations must return `false` for anonymous principals (no
 * authenticated user → no view → restricted column).
 */
interface AttributePermissionReader
{
    public function canViewAttribute(Uuid $attributeId): bool;

    /**
     * Batch counterpart of {@see canViewAttribute()} (#2794).
     *
     * Read overlays gate a whole page of items against the tenant's
     * attribute catalogue, so asking one id at a time turned into a
     * constant per-request query multiplier (GOLIVE #2234: 32 attributes
     * × 4 SELECTs = 128 queries on every `GET /api/products`, regardless
     * of `itemsPerPage`). Implementations must answer the whole set in a
     * bounded number of round-trips.
     *
     * @param list<Uuid> $attributeIds
     *
     * @return array<string, bool> keyed by RFC4122 attribute id; every input id is present
     */
    public function canViewAttributes(array $attributeIds): array;

    public function canEditAttribute(Uuid $attributeId): bool;

    /**
     * AUD-008 (#1578) — whether per-attribute permissions apply to the
     * current principal at all.
     *
     * Write paths ({@see \App\Catalog\Application\ObjectAttributesUpserter})
     * are also reachable from system contexts with no security token — CLI
     * backfills, fixtures, async system jobs. Those carry no domain user, so
     * there is no per-attribute grant to enforce and they must NOT be blocked
     * by the anonymous-→-restricted default that {@see canEditAttribute()}
     * returns. This predicate is `true` only when the current principal is a
     * domain {@see \App\Identity\Domain\Entity\User} the policy can resolve.
     */
    public function isAttributePermissionEnforced(): bool;
}
