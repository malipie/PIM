<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-07 (#1950) — execution context handed to every tool call.
 *
 * The agent always acts WITHIN the permissions of the initiating user
 * (ADR-0024 b): userId identifies whose RBAC scope applies, tenant
 * scopes every engine call. View context (active filter DSL, selection,
 * locale/channel) is carried in `viewContext` verbatim from
 * agent_runs.context (P1-01).
 *
 * @phpstan-type ViewContext array<string, mixed>
 */
final readonly class AgentToolContext
{
    /**
     * @param array<string, mixed> $viewContext
     */
    public function __construct(
        public Uuid $userId,
        public Tenant $tenant,
        public array $viewContext = [],
    ) {
    }
}
