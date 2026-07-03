<?php

declare(strict_types=1);

namespace App\Agent\Application\Llm;

use App\Shared\Domain\Tenant;

/**
 * AGENT-P1-03 (#1955) — thin seam over the Anthropic Messages API so
 * the loop is deterministic and fully testable without a live model
 * (tests script responses; the SDK adapter is the only network code).
 */
interface AgentLlmClientInterface
{
    /**
     * @param list<array<string, mixed>> $messages      Anthropic message shapes (camelCase SDK keys)
     * @param list<array<string, mixed>> $tools         tool definitions from the registry
     * @param bool                       $promptCaching when true, mark the stable system+tools prefix
     *                                                  with an ephemeral cache breakpoint
     */
    public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse;
}
