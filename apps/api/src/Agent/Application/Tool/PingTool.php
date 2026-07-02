<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

/**
 * AGENT-P0-07 (#1950) — trivial validation tool proving the registry
 * contract end-to-end (declaration -> RBAC filter -> execute). Gated on
 * catalog read so any user who can see the catalog can ping; it leaks
 * nothing beyond the tenant code the user is already operating in.
 */
final readonly class PingTool implements AgentToolInterface
{
    public function name(): string
    {
        return 'ping';
    }

    public function description(): string
    {
        return 'Health-check tool. Call it only when asked to verify the agent tooling works; it returns a pong with the current tenant code.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'echo' => [
                    'type' => 'string',
                    'description' => 'Optional text echoed back verbatim.',
                ],
            ],
            'required' => [],
        ];
    }

    public function requiredPermission(): string
    {
        return 'object.read';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $echo = $arguments['echo'] ?? null;

        return [
            'pong' => true,
            'tenant' => $context->tenant->getCode(),
            'echo' => \is_string($echo) ? $echo : null,
        ];
    }
}
