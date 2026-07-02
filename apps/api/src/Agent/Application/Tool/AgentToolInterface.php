<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

/**
 * AGENT-P0-07 (#1950) — the tool contract of the thin agent layer
 * (ADR-0024 b): every tool is a thin adapter over a `*\Contracts` port
 * of its host engine, declared to the model via the registry.
 *
 * Adding a tool = implement this interface (auto-tagged `agent.tool`)
 * + the host-BC Contracts port. Zero changes in the agent loop.
 */
interface AgentToolInterface
{
    /**
     * Stable tool name exposed to the model (snake_case).
     */
    public function name(): string;

    /**
     * Model-facing description — be prescriptive about WHEN to call it.
     */
    public function description(): string;

    /**
     * JSON Schema of the arguments (Anthropic tool-use `input_schema`
     * shape: type/properties/required).
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Coarse RBAC permission code (`resource.action`, PRD-PIM-rbac §3.2)
     * required to SEE and EXECUTE this tool. Fine-grained per-attribute/
     * locale/channel checks are layered per call in P1-02.
     */
    public function requiredPermission(): string;

    public function kind(): ToolKind;

    /**
     * Execute with validated-by-the-registry access. Returns the
     * tool_result payload for the model (JSON-serializable; MUST NOT
     * contain secrets).
     *
     * @param array<string, mixed> $arguments parsed tool-use input from the model
     *
     * @return array<string, mixed>
     */
    public function execute(array $arguments, AgentToolContext $context): array;
}
