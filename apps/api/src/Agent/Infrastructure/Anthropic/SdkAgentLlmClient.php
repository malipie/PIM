<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Anthropic;

use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Shared\Domain\Tenant;

/**
 * AGENT-P1-03 (#1955) — the only network-facing piece of the loop:
 * builds the per-tenant SDK client (BYOK, P0-06; retry/backoff lives
 * in the SDK transport) and normalizes the response into the
 * provider-agnostic {@see AgentLlmResponse}.
 */
final readonly class SdkAgentLlmClient implements AgentLlmClientInterface
{
    private const int MAX_TOKENS = 4096;

    public function __construct(
        private AnthropicClientFactory $clientFactory,
    ) {
    }

    public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools): AgentLlmResponse
    {
        $client = $this->clientFactory->forTenant($tenant);

        $message = $client->messages->create(
            model: $model,
            maxTokens: self::MAX_TOKENS,
            system: $system,
            // Wire-shape arrays; the SDK normalizes them (camelCase keys).
            // @phpstan-ignore argument.type
            messages: $messages,
            // @phpstan-ignore argument.type
            tools: $tools,
        );

        $blocks = [];
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $blocks[] = ['type' => 'text', 'text' => $block->text];
            } elseif ($block instanceof ToolUseBlock) {
                $blocks[] = ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            }
        }

        return new AgentLlmResponse(
            stopReason: $message->stopReason ?? AgentLlmResponse::STOP_END_TURN,
            contentBlocks: $blocks,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
        );
    }
}
