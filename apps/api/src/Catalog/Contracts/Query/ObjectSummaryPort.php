<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

/**
 * WFL redesign (#2518) — batch cross-BC read of {@see ObjectSummary} for
 * a set of object ids. The workflow task list (Workflow BC) uses it to
 * label task cards with the object's title / code / kind without
 * crossing into Catalog's Domain (deptrac).
 */
interface ObjectSummaryPort
{
    /**
     * @param list<string> $objectIds RFC-4122 uuids
     *
     * @return array<string, ObjectSummary> keyed by object id (missing ids omitted)
     */
    public function summariesByIds(array $objectIds): array;
}
