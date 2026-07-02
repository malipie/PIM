<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Byok;

use App\Shared\Domain\Tenant;

/**
 * AGENT-P0-06 (#1949) — Contracts seam over the BYOK key lifecycle
 * (ADR-0017), so the removable Agent BC can resolve the tenant's
 * Anthropic key without reaching Identity internals (ADR-0024 b).
 *
 * Implemented by Identity\Application\ByokKeyManager.
 */
interface ByokKeyResolverInterface
{
    /**
     * Plaintext Anthropic API key for the tenant, or `null` if BYOK is
     * not configured / disabled. The caller MUST hand the result
     * straight to the LLM client — never store, log, or echo it.
     */
    public function resolveKey(Tenant $tenant): ?string;
}
