<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P2-01 (#2331, ADR-0030) — Contracts seam over the resolved
 * attribute values of one object, for content grounding. Strictly
 * read-only: implementations MUST NOT touch `attributes_indexed` or
 * `object_values` (jsonb-schemas rule #1 — the cache has exactly one
 * writer); generated content flows back exclusively through the
 * PendingChanges port.
 *
 * Tenant scope comes from the Doctrine TenantFilter / RLS.
 */
interface ObjectFactsPort
{
    /**
     * @param list<string> $attributeCodes the recipe's source attributes;
     *                                     unknown/empty codes surface in
     *                                     ObjectFacts::$missingCodes
     *
     * @throws InvalidArgumentException when the object id is unknown
     */
    public function facts(
        Uuid $objectId,
        array $attributeCodes,
        ?string $locale = null,
        ?string $channel = null,
    ): ObjectFacts;
}
