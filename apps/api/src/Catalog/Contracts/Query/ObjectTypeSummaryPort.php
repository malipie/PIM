<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use Symfony\Component\Uid\Uuid;

/**
 * #2848 — single-id cross-BC read of {@see ObjectTypeSummary}.
 *
 * Identity's collection voter needs one fact about an ObjectType — its
 * {@see \App\Catalog\Domain\ObjectKind} — to decide whether the caller may
 * list objects of that type. Deptrac lets `Identity_Internals` reach
 * `Catalog_Contracts` only, so the lookup goes through this port rather
 * than the repository or the query handler.
 *
 * Tenant scoping is the implementation's business: the Doctrine
 * TenantFilter narrows the lookup, so an id belonging to another tenant
 * resolves to `null` and the caller reads that as "unknown type".
 */
interface ObjectTypeSummaryPort
{
    public function byId(Uuid $objectTypeId): ?ObjectTypeSummary;
}
