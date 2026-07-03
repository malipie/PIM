<?php

declare(strict_types=1);

namespace App\Agent\Application\Cost;

/**
 * AGENT-P9-02 (#1989) — the cost/limits readout for the operator
 * (Piotr): what the tenant spent this day/month, the tokens behind it,
 * and how close each spend is to the §8.5 cap.
 */
final readonly class AgentCostReport
{
    /**
     * @param list<array{user_id: string, runs: int, tokens: int, cost_usd: string}> $perUserToday
     */
    public function __construct(
        public string $costTodayUsd,
        public string $costMonthUsd,
        public int $tokensToday,
        public int $tokensMonth,
        public int $runsToday,
        public int $runsMonth,
        public float $dayCapUsd,
        public float $monthCapUsd,
        public float $dayCapPct,
        public float $monthCapPct,
        public array $perUserToday,
    ) {
    }
}
