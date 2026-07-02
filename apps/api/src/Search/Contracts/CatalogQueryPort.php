<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use InvalidArgumentException;

/**
 * AGENT-P2-01 (#1958) — Contracts seam over catalog search for
 * cross-BC consumers (the removable Agent BC first): full-text query +
 * filter DSL -> tenant-scoped hits with a real total (grounding for
 * "plans made of concrete numbers", ADR-0024 b).
 *
 * The filter DSL is validated against the attribute registry before it
 * reaches the engine (unknown fields / operators throw); tenant scope
 * comes from the active tenant context inside the engine.
 */
interface CatalogQueryPort
{
    /**
     * @param array<string, mixed> $filterDsl canonical filter DSL ([] = match all)
     *
     * @throws InvalidArgumentException on invalid DSL (unknown field/operator)
     */
    public function search(string $kind, string $query = '', array $filterDsl = [], int $page = 1, int $perPage = 20): CatalogQueryResult;
}
