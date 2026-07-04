<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Agent\Domain\Exception\ToolAccessDeniedException;
use App\Identity\Contracts\Policy\AgentAutonomyResolverInterface;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-07 (#1950) — declarative tool registry (ADR-0024 b).
 *
 * The model gets ONLY the tools the initiating user is RBAC-allowed to
 * use ({@see availableFor}); and even a direct/forged tool_use call is
 * re-checked at {@see execute} (fail closed, SEC). Adding a tool is a
 * service implementing AgentToolInterface — the loop never changes.
 */
final readonly class ToolRegistry
{
    /** @var list<AgentToolInterface> */
    private array $tools;

    /**
     * @param iterable<AgentToolInterface> $tools
     */
    public function __construct(
        #[AutowireIterator('agent.tool')]
        iterable $tools,
        private PermissionCheckerInterface $permissions,
        private ?AgentAutonomyResolverInterface $autonomy = null,
    ) {
        $indexed = [];
        foreach ($tools as $tool) {
            $indexed[$tool->name()] = $tool;
        }
        $this->tools = array_values($indexed);
    }

    /**
     * @return list<AgentToolInterface>
     */
    public function availableFor(Uuid $userId): array
    {
        // AGENT-P8-05 (#1987) — the role-level autonomy gate runs BEFORE
        // per-tool permissions: 'off' = empty surface (a run refuses to
        // plan), 'read_only' = grounding tools only, 'propose' = full
        // surface with write/schema still behind the approval gate.
        $level = $this->autonomy?->autonomyForUser($userId) ?? 'propose';
        if ('off' === $level) {
            return [];
        }

        return array_values(array_filter(
            $this->tools,
            fn (AgentToolInterface $tool): bool => ('read_only' !== $level || ToolKind::Read === $tool->kind())
                && $this->permissions->userHasPermission($userId, $tool->requiredPermission()),
        ));
    }

    /**
     * #2246 — quick-action chips for the user-visible subset (dashboard
     * hero + Cmd+K). RBAC/autonomy filtering comes for free via
     * {@see availableFor}; a tool opts in by implementing
     * {@see ProvidesQuickActionInterface}.
     *
     * @return list<AgentQuickAction>
     */
    public function quickActionsFor(Uuid $userId): array
    {
        $actions = [];
        foreach ($this->availableFor($userId) as $tool) {
            if ($tool instanceof ProvidesQuickActionInterface) {
                $actions[] = $tool->quickAction();
            }
        }

        usort(
            $actions,
            static fn (AgentQuickAction $a, AgentQuickAction $b): int => [$a->priority, $a->id] <=> [$b->priority, $b->id],
        );

        return $actions;
    }

    /**
     * Anthropic tool-use definitions for the user-visible subset
     * (PHP SDK camelCase key `inputSchema`).
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function toAnthropicToolDefs(Uuid $userId): array
    {
        return array_map(
            static fn (AgentToolInterface $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->parametersSchema(),
            ],
            $this->availableFor($userId),
        );
    }

    /**
     * Whether any of the user-visible tools is a schema-op — the loop
     * uses this to pick the Opus-tier model (P0-06 AgentModelSelector).
     */
    public function hasKind(Uuid $userId, ToolKind $kind): bool
    {
        foreach ($this->availableFor($userId) as $tool) {
            if ($kind === $tool->kind()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guarded execution entry: unknown tool and missing permission are
     * indistinguishable to the caller (no tool enumeration beyond the
     * user's scope).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws ToolAccessDeniedException
     */
    public function execute(string $toolName, array $arguments, AgentToolContext $context): array
    {
        $tool = $this->find($toolName);
        if (null === $tool) {
            throw ToolAccessDeniedException::unknownTool($toolName);
        }

        if (!$this->permissions->userHasPermission($context->userId, $tool->requiredPermission())) {
            throw ToolAccessDeniedException::forTool($toolName);
        }

        return $tool->execute($arguments, $context);
    }

    public function find(string $toolName): ?AgentToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $toolName) {
                return $tool;
            }
        }

        return null;
    }
}
