<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;

/**
 * AGENT-P0-06 (#1949) — the agent cannot run for this tenant.
 *
 * Raised by the Anthropic client factory when the tenant has no active
 * BYOK key (ADR-0017: no key -> agent off, never a fallback to someone
 * else's key) and by AgentFeatureGuard (P0-08) when the feature flag is
 * off. Messages MUST never contain key material.
 */
final class AgentUnavailableException extends RuntimeException
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
}
