<?php

declare(strict_types=1);

namespace App\Agent\Application\Llm;

use App\Shared\Domain\Tenant;

/**
 * Interactive extension of the LLM seam. Implementations stream visible
 * text deltas while still returning the canonical, complete response used by
 * the tool loop and persistence layer.
 */
interface StreamingAgentLlmClientInterface extends AgentLlmClientInterface
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param callable(string): void     $onTextDelta
     */
    public function createStreaming(
        Tenant $tenant,
        string $model,
        string $system,
        array $messages,
        array $tools,
        bool $promptCaching,
        callable $onTextDelta,
    ): AgentLlmResponse;
}
