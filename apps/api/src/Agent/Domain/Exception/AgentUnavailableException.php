<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AGENT-P0-06 (#1949) / AGENT-P0-08 (#1951) — the agent cannot run for
 * this tenant.
 *
 * Raised by the Anthropic client factory when the tenant has no active
 * BYOK key (ADR-0017: no key -> agent off, never a fallback to someone
 * else's key) and by AgentFeatureGuard when the feature flag is off.
 * Implements HttpExceptionInterface so the shared RFC 7807 listener
 * renders it as a 403 problem document on the /api/agent/* endpoints
 * (P4-01) without per-controller mapping. Messages MUST never contain
 * key material.
 */
final class AgentUnavailableException extends RuntimeException implements HttpExceptionInterface
{
    public static function missingByokKey(): self
    {
        return new self(
            'Agent is unavailable for this tenant: no active Anthropic BYOK key is configured. '
            .'Set a key in Settings -> AI to enable the agent.'
        );
    }

    public static function featureDisabled(): self
    {
        return new self('Agent is disabled: the agent feature flag is off for this deployment.');
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
