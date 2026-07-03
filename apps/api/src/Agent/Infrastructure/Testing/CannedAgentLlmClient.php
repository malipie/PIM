<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Testing;

use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Shared\Domain\Tenant;

/**
 * AGENT-P4-01 (#1968) — deterministic LLM stand-in for the test
 * environment ONLY (when@test alias in services_agent.yaml; the
 * discovery excludes this directory so the SDK client stays the sole
 * implementation everywhere else). Always answers with a plain-text
 * clarifying turn, so an API-driven run deterministically lands on
 * awaiting_input without any network. Tool-driven trajectories are
 * exercised by the scripted clients in the integration suite.
 */
final readonly class CannedAgentLlmClient implements AgentLlmClientInterface
{
    public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools): AgentLlmResponse
    {
        return new AgentLlmResponse(
            stopReason: AgentLlmResponse::STOP_END_TURN,
            contentBlocks: [['type' => 'text', 'text' => 'Canned test reply: which objects should I target?']],
            inputTokens: 12,
            outputTokens: 8,
        );
    }
}
