<?php

declare(strict_types=1);

namespace App\Import\Domain\Message;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * #2818 — undoing an import is a worker job, like running one.
 *
 * The asymmetry it replaces had no justification: an import of 50 000 rows is
 * queued, checkpointed, cancellable and reported on, while undoing that same
 * import was one synchronous HTTP request. Reversing a change is about as much
 * work as making it and sometimes more, because the prior state has to be read
 * back first — measured at over ten minutes for a 13 895-object session, which
 * no request budget covers. A 24-hour rollback window is a promise the system
 * could not keep.
 *
 * Routed to the `import` transport (the queue the worker drains) and dispatched
 * on `messenger.bus.long_running`, so the run manages its own transaction
 * boundaries — see {@see \App\Import\Application\Handler\ImportRollbackHandler}.
 */
final readonly class ImportRollbackMessage implements TenantAwareMessage
{
    public function __construct(
        public Uuid $importSessionId,
        public Uuid $tenantId,
    ) {
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
