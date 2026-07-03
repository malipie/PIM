<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Byok;

use App\Shared\Domain\Tenant;

/**
 * AGENT-P8-01 (#1983) — read-side of the tenant agent configuration
 * beyond the key itself (the proactive-scan opt-in). Separate from
 * ByokKeyResolverInterface so key material and behaviour flags do not
 * travel through one interface.
 */
interface ByokConfigReaderInterface
{
    public function isProactiveScanEnabled(Tenant $tenant): bool;

    /**
     * Per-tenant model override, or `null` for the platform default
     * (the Agent's AgentModelSelector then picks Sonnet/Opus per tool
     * kind). A concrete id pins every kind to that model.
     */
    public function modelOverride(Tenant $tenant): ?string;

    /**
     * Whether the tenant opted into Anthropic prompt caching (ephemeral
     * cache on the stable system+tools prefix). Defaults to true when
     * no config row exists.
     */
    public function isPromptCachingEnabled(Tenant $tenant): bool;
}
