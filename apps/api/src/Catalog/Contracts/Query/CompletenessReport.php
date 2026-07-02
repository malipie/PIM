<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

/**
 * AGENT-P2-03 (#1960) — aggregate completeness picture for one
 * ObjectType: how complete the data is and WHICH required attributes
 * are missing how often (the substrate of "category X is at 62% -
 * `ean` is missing on 340 products").
 */
final readonly class CompletenessReport
{
    /**
     * @param list<array{code: string, missing_count: int}> $missingRequired sorted by missing_count desc
     */
    public function __construct(
        public string $objectTypeCode,
        public int $totalObjects,
        public int $averagePct,
        public int $belowThresholdCount,
        public int $thresholdPct,
        public array $missingRequired,
    ) {
    }
}
