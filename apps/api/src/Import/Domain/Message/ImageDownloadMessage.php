<?php

declare(strict_types=1);

namespace App\Import\Domain\Message;

use Symfony\Component\Uid\Uuid;

/**
 * IMP2-1.12 — a batch of media-download work buffered by one import chunk and
 * dispatched (after the row writes) to the dedicated `import` transport. The
 * {@see \App\Import\Application\Handler\ImageDownloadHandler} downloads, ingests
 * (dedup) and links the assets, updates the session image counters, and
 * decrements the session's pending-batch counter — the last batch finalizes
 * the session. Media failures never fail the session (warning, not error).
 */
final readonly class ImageDownloadMessage
{
    /**
     * @param list<ImageDownloadJob> $jobs
     * @param ?string                $zipStoragePath MinIO path of the run's ZIP (zip mode, IMP2-1.13); null in http mode
     * @param ?Uuid                  $batchId        idempotency key (#2731) — the session records accounted batches, so a
     *                                               redelivery after a partial run cannot double-count images or duplicate
     *                                               logs. Null only for messages serialized before #2731 (legacy path:
     *                                               accounted unconditionally, as before)
     */
    public function __construct(
        public Uuid $importSessionId,
        public Uuid $tenantId,
        public array $jobs,
        public ?string $zipStoragePath = null,
        public ?Uuid $batchId = null,
    ) {
    }
}
