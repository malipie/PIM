<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Anthropic;

use Anthropic\Client;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Shared\Domain\Tenant;

/**
 * AGENT-P0-06 (#1949) — builds an Anthropic SDK client on the tenant's
 * BYOK key (ADR-0017, ADR-0024 b).
 *
 * - No active key -> {@see AgentUnavailableException}; there is NO
 *   fallback to a platform/env key (`agent off per tenant` semantics,
 *   consistent with AgentFeatureGuard, P0-08).
 * - Retry/backoff on 429/5xx is delegated to the SDK transport: it
 *   honours the `retry-after` header (fallback: exponential, capped)
 *   and is bounded by `maxRetries`. Exhaustion surfaces as the SDK's
 *   RateLimitException / InternalServerException, which the agent loop
 *   (P1-03) maps to run status `error` — never a partial commit
 *   (commits happen only post-approval, P3-02).
 * - The plaintext key goes straight into the client and is never
 *   logged; UI display uses TenantAgentConfig.key_prefix.
 */
final readonly class AnthropicClientFactory
{
    public function __construct(
        private ByokKeyResolverInterface $keyResolver,
        private AnthropicClientBuilderInterface $clientBuilder,
        private int $maxRetries,
        private float $timeoutSeconds,
    ) {
    }

    public function forTenant(Tenant $tenant): Client
    {
        $apiKey = $this->keyResolver->resolveKey($tenant);
        if (null === $apiKey || '' === $apiKey) {
            throw AgentUnavailableException::missingByokKey();
        }

        return $this->clientBuilder->build(
            apiKey: $apiKey,
            requestOptions: [
                'maxRetries' => $this->maxRetries,
                'timeout' => $this->timeoutSeconds,
            ],
        );
    }
}
