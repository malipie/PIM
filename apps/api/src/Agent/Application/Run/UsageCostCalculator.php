<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Infrastructure\Anthropic\AgentModelSelector;

/**
 * AGENT-P1-03 (#1955) — per-call USD cost from token usage. Prices are
 * configuration (per MTok, matching the two model tiers of the
 * selector) so a pricing change never touches code. Cost feeds
 * agent_runs accounting and the 8.5 limits (P1-04).
 */
final readonly class UsageCostCalculator
{
    /**
     * Prompt-cache multipliers on the input rate (Anthropic pricing):
     * a cache READ costs ~0.1x base input; a cache WRITE costs ~1.25x
     * (5-minute TTL — the default the client uses).
     */
    private const float CACHE_READ_MULTIPLIER = 0.1;
    private const float CACHE_WRITE_MULTIPLIER = 1.25;

    public function __construct(
        private AgentModelSelector $models,
        private float $defaultInputPerMtok,
        private float $defaultOutputPerMtok,
        private float $schemaInputPerMtok,
        private float $schemaOutputPerMtok,
    ) {
    }

    /**
     * @param int $cacheReadTokens     tokens served from the cache (billed at ~0.1x input)
     * @param int $cacheCreationTokens tokens written to the cache (billed at ~1.25x input)
     *
     * @return numeric-string USD with 6 decimals
     */
    public function costUsd(string $model, int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheCreationTokens = 0): string
    {
        // A per-tenant model override (e.g. Haiku) is billed at the
        // default tier here — a conservative over-estimate for limits;
        // exact per-model pricing is a follow-up if it ever matters.
        [$in, $out] = $model === $this->models->schemaModel()
            ? [$this->schemaInputPerMtok, $this->schemaOutputPerMtok]
            : [$this->defaultInputPerMtok, $this->defaultOutputPerMtok];

        $cost = (
            $inputTokens * $in
            + $outputTokens * $out
            + $cacheReadTokens * $in * self::CACHE_READ_MULTIPLIER
            + $cacheCreationTokens * $in * self::CACHE_WRITE_MULTIPLIER
        ) / 1_000_000;

        return number_format($cost, 6, '.', '');
    }
}
