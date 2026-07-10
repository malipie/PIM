<?php

declare(strict_types=1);

namespace App\Agent\Application\Cost;

/**
 * AICG-P6-03 (#2346, ADR-0030) — the deterministic pre-flight estimate
 * a bulk content run costs, so the UI can show it and the day-cap gate
 * can refuse BEFORE any tokens are spent (plan §8 R3).
 */
final readonly class ContentCostEstimate
{
    /**
     * @param numeric-string $estCostUsd
     */
    public function __construct(
        public int $productCount,
        public int $inputTokensPerProduct,
        public int $outputTokensPerProduct,
        public int $estInputTokens,
        public int $estOutputTokens,
        public string $estCostUsd,
        public string $model,
    ) {
    }
}
