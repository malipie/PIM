<?php

declare(strict_types=1);

namespace App\Search\Contracts;

/**
 * AGENT-P2-01 (#1958) — result of a grounding query. `hits` carries the
 * caller-shaped projection rows; `degraded` mirrors the search-engine
 * outage flag so consumers can say "I could not verify" instead of
 * hallucinating counts.
 */
final readonly class CatalogQueryResult
{
    /**
     * @param list<array<string, mixed>> $hits
     */
    public function __construct(
        public array $hits,
        public int $totalHits,
        public bool $degraded,
    ) {
    }
}
