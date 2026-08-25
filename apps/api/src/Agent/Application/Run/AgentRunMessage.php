<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-03 (#1955) — async trigger of the loop. TenantAwareMessage
 * so the worker middlewares rebind the tenant context + RLS GUC before
 * the handler touches the database. Routed to the dedicated `agent`
 * transport. `enqueuedAtMs` measures each turn's real queue delay.
 */
final readonly class AgentRunMessage implements TenantAwareMessage
{
    public int $enqueuedAtMs;

    public function __construct(
        public Uuid $runId,
        public Uuid $tenantId,
        ?int $enqueuedAtMs = null,
    ) {
        $this->enqueuedAtMs = $enqueuedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
