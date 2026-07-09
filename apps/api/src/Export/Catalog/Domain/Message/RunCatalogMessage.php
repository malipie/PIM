<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Message;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * CPDF-P3-01 — Symfony Messenger envelope for async PDF catalog generation.
 *
 * Dispatched by the manual "Generuj teraz" endpoint (and later the cron
 * scheduler) onto the shared `import` queue. Carries the
 * {@see \App\Export\Catalog\Domain\Entity\CatalogRun} UUID — the handler loads
 * the persisted run so the message is replay-safe (a retry resumes from
 * recorded state instead of re-reading stale config).
 *
 * Implements {@see TenantAwareMessage} so the
 * {@see \App\Shared\Infrastructure\Messenger\TenantContextRebindingMiddleware}
 * rebinds tenant + RLS GUC on the worker before the handler touches any
 * tenant-scoped row (HIGH-002).
 */
final class RunCatalogMessage implements TenantAwareMessage
{
    public function __construct(
        public readonly Uuid $catalogRunId,
        public readonly Uuid $tenantId,
    ) {
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
