<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Controller;

use App\Agent\Application\Cost\AgentCostAggregator;
use App\Agent\Application\Cost\AgentCostReport;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AGENT-P9-02 (#1989) — cost/limits dashboard endpoint (PRD §10.4):
 * the tenant's day/month spend, tokens, run counts, progress to the
 * §8.5 caps and today's top spenders. Gated by settings.tenant.manage
 * (Piotr's operator surface).
 */
final readonly class AgentCostController
{
    public function __construct(
        private AgentCostAggregator $aggregator,
    ) {
    }

    #[Route('/api/agent/cost', name: 'pim_agent_cost', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'tenant.manage')]
    public function report(Request $request): JsonResponse|Response
    {
        $report = $this->aggregator->report();

        // Prometheus scrape variant (removability: agent metrics stay in
        // the Agent BC, not the core Shared MetricsController).
        if ('prometheus' === $request->query->get('format')) {
            $body = <<<METRICS
                # HELP agent_cost_usd_today Agent spend for the tenant this UTC day.
                # TYPE agent_cost_usd_today gauge
                agent_cost_usd_today {$report->costTodayUsd}
                # HELP agent_cost_usd_month Agent spend for the tenant this UTC month.
                # TYPE agent_cost_usd_month gauge
                agent_cost_usd_month {$report->costMonthUsd}
                # HELP agent_cost_day_cap_pct Progress toward the daily spend cap (percent).
                # TYPE agent_cost_day_cap_pct gauge
                agent_cost_day_cap_pct {$report->dayCapPct}
                # HELP agent_cost_month_cap_pct Progress toward the monthly spend cap (percent).
                # TYPE agent_cost_month_cap_pct gauge
                agent_cost_month_cap_pct {$report->monthCapPct}
                # HELP agent_runs_today Agent runs started for the tenant this UTC day.
                # TYPE agent_runs_today gauge
                agent_runs_today {$report->runsToday}
                # HELP agent_tokens_today Agent tokens spent for the tenant this UTC day.
                # TYPE agent_tokens_today gauge
                agent_tokens_today {$report->tokensToday}
                # HELP agent_llm_calls_today Agent LLM calls for the tenant this UTC day.
                # TYPE agent_llm_calls_today gauge
                agent_llm_calls_today {$report->llmCallsToday}
                # HELP agent_cache_read_tokens_today Agent prompt-cache read tokens this UTC day.
                # TYPE agent_cache_read_tokens_today gauge
                agent_cache_read_tokens_today {$report->cacheReadTokensToday}
                # HELP agent_queue_delay_ms_today Average worker queue delay this UTC day.
                # TYPE agent_queue_delay_ms_today gauge
                agent_queue_delay_ms_today {$report->avgQueueDelayMsToday}
                # HELP agent_llm_duration_ms_today Average LLM call duration this UTC day.
                # TYPE agent_llm_duration_ms_today gauge
                agent_llm_duration_ms_today {$report->avgLlmDurationMsToday}
                # HELP agent_llm_ttft_ms_today Average LLM time to first text token this UTC day.
                # TYPE agent_llm_ttft_ms_today gauge
                agent_llm_ttft_ms_today {$report->avgLlmTtftMsToday}
                METRICS;

            return new Response($body, Response::HTTP_OK, ['content-type' => 'text/plain; version=0.0.4']);
        }

        return new JsonResponse($this->serialize($report));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AgentCostReport $report): array
    {
        return [
            'cost_today_usd' => $report->costTodayUsd,
            'cost_month_usd' => $report->costMonthUsd,
            'tokens_today' => $report->tokensToday,
            'tokens_month' => $report->tokensMonth,
            'runs_today' => $report->runsToday,
            'runs_month' => $report->runsMonth,
            'llm_calls_today' => $report->llmCallsToday,
            'cache_read_tokens_today' => $report->cacheReadTokensToday,
            'cache_creation_tokens_today' => $report->cacheCreationTokensToday,
            'avg_queue_delay_ms_today' => $report->avgQueueDelayMsToday,
            'avg_llm_duration_ms_today' => $report->avgLlmDurationMsToday,
            'avg_llm_ttft_ms_today' => $report->avgLlmTtftMsToday,
            'day_cap_usd' => $report->dayCapUsd,
            'month_cap_usd' => $report->monthCapUsd,
            'day_cap_pct' => $report->dayCapPct,
            'month_cap_pct' => $report->monthCapPct,
            'per_user_today' => $report->perUserToday,
        ];
    }
}
