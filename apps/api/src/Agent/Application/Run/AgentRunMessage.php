<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-03 (#1955) — async trigger of the loop. TenantAwareMessage
 * so the worker middlewares rebind the tenant context + RLS GUC before
 * the handler touches the database. Routed to the `import` transport
 * (the queue the dev/prod worker consumes) via config/packages/agent.yaml.
 */
final readonly class AgentRunMessage implements TenantAwareMessage
{
    public function __construct(
        public Uuid $runId,
        public Uuid $tenantId,
    ) {
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
