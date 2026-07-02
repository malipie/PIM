<?php

declare(strict_types=1);

namespace App\Agent\Application;

use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Shared\Domain\Tenant;

/**
 * AGENT-P0-08 (#1951) — two-layer agent availability gate:
 *
 *   1. global feature flag (`AGENT_ENABLED` env, open-core: a deploy
 *      without the agent flips it off and the API surface refuses);
 *   2. per-tenant BYOK key (ADR-0017): no active key -> agent off for
 *      that tenant — a deliberate consequence, not an error state.
 *
 * Wired into every /api/agent/* endpoint (P4-01) and the loop start
 * (P1-03). Refusal renders as a 403 RFC 7807 problem document via the
 * shared listener (AgentUnavailableException implements
 * HttpExceptionInterface) — never a 500.
 */
final readonly class AgentFeatureGuard
{
    public function __construct(
        private ByokKeyResolverInterface $keyResolver,
        private bool $agentEnabled,
    ) {
    }

    /**
     * @throws AgentUnavailableException
     */
    public function assertEnabled(Tenant $tenant): void
    {
        if (!$this->agentEnabled) {
            throw AgentUnavailableException::featureDisabled();
        }

        if (!$this->keyResolver->hasActiveKey($tenant)) {
            throw AgentUnavailableException::missingByokKey();
        }
    }

    public function isEnabled(Tenant $tenant): bool
    {
        return $this->agentEnabled && $this->keyResolver->hasActiveKey($tenant);
    }
}
