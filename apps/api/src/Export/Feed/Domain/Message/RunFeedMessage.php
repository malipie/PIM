<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Message;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P4-02 (#1926) — Symfony Messenger envelope for async feed regeneration.
 *
 * Dispatched by the manual "Regeneruj teraz" endpoint (and later the cron
 * scheduler + first-publish trigger) onto the shared `import` queue. Carries
 * the {@see \App\Export\Feed\Domain\Entity\FeedRun} UUID — the handler loads
 * the persisted run so the message is replay-safe (a retry resumes from
 * recorded state instead of re-reading stale config).
 *
 * Implements {@see TenantAwareMessage} so the
 * {@see \App\Shared\Infrastructure\Messenger\TenantContextRebindingMiddleware}
 * rebinds tenant + RLS GUC on the worker before the handler touches any
 * tenant-scoped row (HIGH-002).
 */
final class RunFeedMessage implements TenantAwareMessage
{
    public function __construct(
        public readonly Uuid $feedRunId,
        public readonly Uuid $tenantId,
    ) {
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
