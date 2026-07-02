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
    public function __construct(
        private AgentModelSelector $models,
        private float $defaultInputPerMtok,
        private float $defaultOutputPerMtok,
        private float $schemaInputPerMtok,
        private float $schemaOutputPerMtok,
    ) {
    }

    /**
     * @return numeric-string USD with 6 decimals
     */
    public function costUsd(string $model, int $inputTokens, int $outputTokens): string
    {
        [$in, $out] = $model === $this->models->schemaModel()
            ? [$this->schemaInputPerMtok, $this->schemaOutputPerMtok]
            : [$this->defaultInputPerMtok, $this->defaultOutputPerMtok];

        $cost = ($inputTokens * $in + $outputTokens * $out) / 1_000_000;

        return number_format($cost, 6, '.', '');
    }
}
