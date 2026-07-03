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
}
