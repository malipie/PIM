<?php

declare(strict_types=1);

namespace App\Agent\Application\Cost;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use LogicException;

/**
 * AGENT-P9-02 (#1989) — aggregates agent_runs cost/tokens per tenant
 * for the current UTC day and month (the §8.5 cap windows), plus the
 * top spenders today, and the progress toward each cap. Read-only over
 * the canonical agent_runs; the caps come from the same knobs
 * AgentLimitGuard enforces.
 */
final readonly class AgentCostAggregator
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private float $dayCapUsd,
        private float $monthCapUsd,
    ) {
    }

    public function report(): AgentCostReport
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot aggregate agent cost without a current tenant.');
        }
        $tenantId = $tenant->getId()->toRfc4122();

        $today = $this->windowTotals($tenantId, "date_trunc('day', now() AT TIME ZONE 'UTC')");
        $month = $this->windowTotals($tenantId, "date_trunc('month', now() AT TIME ZONE 'UTC')");

        $costToday = (float) $today['cost'];
        $costMonth = (float) $month['cost'];

        return new AgentCostReport(
            costTodayUsd: $today['cost'],
            costMonthUsd: $month['cost'],
            tokensToday: $today['tokens'],
            tokensMonth: $month['tokens'],
            runsToday: $today['runs'],
            runsMonth: $month['runs'],
            llmCallsToday: $today['llm_calls'],
            cacheReadTokensToday: $today['cache_read_tokens'],
            cacheCreationTokensToday: $today['cache_creation_tokens'],
            avgQueueDelayMsToday: $today['avg_queue_delay_ms'],
            avgLlmDurationMsToday: $today['avg_llm_duration_ms'],
            avgLlmTtftMsToday: $today['avg_llm_ttft_ms'],
            dayCapUsd: $this->dayCapUsd,
            monthCapUsd: $this->monthCapUsd,
            dayCapPct: $this->dayCapUsd > 0 ? round(min(100, $costToday / $this->dayCapUsd * 100), 1) : 0.0,
            monthCapPct: $this->monthCapUsd > 0 ? round(min(100, $costMonth / $this->monthCapUsd * 100), 1) : 0.0,
            perUserToday: $this->perUserToday($tenantId),
        );
    }

    /**
     * @return array{cost: string, tokens: int, runs: int, llm_calls: int, cache_read_tokens: int, cache_creation_tokens: int, avg_queue_delay_ms: int, avg_llm_duration_ms: int, avg_llm_ttft_ms: int}
     */
    private function windowTotals(string $tenantId, string $windowStartSql): array
    {
        // tenant-safe: explicit tenant_id predicate; RLS is the backstop.
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(cost_usd), 0)::text AS cost,
                    COALESCE(SUM(tokens_input + tokens_output), 0) AS tokens,
                    COALESCE(SUM(llm_calls), 0) AS llm_calls,
                    COALESCE(SUM(cache_read_tokens), 0) AS cache_read_tokens,
                    COALESCE(SUM(cache_creation_tokens), 0) AS cache_creation_tokens,
                    COALESCE(ROUND(AVG(queue_delay_ms)), 0) AS avg_queue_delay_ms,
                    COALESCE(ROUND(SUM(llm_duration_ms)::numeric / NULLIF(SUM(llm_calls), 0)), 0) AS avg_llm_duration_ms,
                    COALESCE(ROUND(SUM(llm_ttft_ms)::numeric / NULLIF(SUM(llm_calls), 0)), 0) AS avg_llm_ttft_ms,
                    COUNT(*) AS runs
               FROM agent_runs
              WHERE tenant_id = :tenant AND started_at >= '.$windowStartSql,
            ['tenant' => $tenantId],
        );

        return [
            'cost' => \is_string($row['cost'] ?? null) ? $row['cost'] : '0',
            'tokens' => \is_numeric($row['tokens'] ?? null) ? (int) $row['tokens'] : 0,
            'runs' => \is_numeric($row['runs'] ?? null) ? (int) $row['runs'] : 0,
            'llm_calls' => \is_numeric($row['llm_calls'] ?? null) ? (int) $row['llm_calls'] : 0,
            'cache_read_tokens' => \is_numeric($row['cache_read_tokens'] ?? null) ? (int) $row['cache_read_tokens'] : 0,
            'cache_creation_tokens' => \is_numeric($row['cache_creation_tokens'] ?? null) ? (int) $row['cache_creation_tokens'] : 0,
            'avg_queue_delay_ms' => \is_numeric($row['avg_queue_delay_ms'] ?? null) ? (int) $row['avg_queue_delay_ms'] : 0,
            'avg_llm_duration_ms' => \is_numeric($row['avg_llm_duration_ms'] ?? null) ? (int) $row['avg_llm_duration_ms'] : 0,
            'avg_llm_ttft_ms' => \is_numeric($row['avg_llm_ttft_ms'] ?? null) ? (int) $row['avg_llm_ttft_ms'] : 0,
        ];
    }

    /**
     * @return list<array{user_id: string, runs: int, tokens: int, cost_usd: string}>
     */
    private function perUserToday(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT user_id::text AS user_id,
                    COUNT(*) AS runs,
                    COALESCE(SUM(tokens_input + tokens_output), 0) AS tokens,
                    COALESCE(SUM(cost_usd), 0)::text AS cost_usd
               FROM agent_runs
              WHERE tenant_id = :tenant AND started_at >= date_trunc('day', now() AT TIME ZONE 'UTC')
              GROUP BY user_id
              ORDER BY SUM(cost_usd) DESC
              LIMIT 20",
            ['tenant' => $tenantId],
        );

        $perUser = [];
        foreach ($rows as $row) {
            if (!\is_string($row['user_id'] ?? null)) {
                continue;
            }
            $perUser[] = [
                'user_id' => $row['user_id'],
                'runs' => \is_numeric($row['runs'] ?? null) ? (int) $row['runs'] : 0,
                'tokens' => \is_numeric($row['tokens'] ?? null) ? (int) $row['tokens'] : 0,
                'cost_usd' => \is_string($row['cost_usd'] ?? null) ? $row['cost_usd'] : '0',
            ];
        }

        return $perUser;
    }
}
