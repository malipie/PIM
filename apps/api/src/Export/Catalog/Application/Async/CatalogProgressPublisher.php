<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Async;

use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * CPDF-P3-01 — Mercure SSE publisher for PDF catalog generation runs, the
 * catalog-world mirror of
 * {@see \App\Export\Feed\Application\Async\FeedProgressPublisher}.
 *
 * Publishes lifecycle events to the tenant-scoped, private per-catalog topic
 * `tenant/{tid}/catalogs/{catalog_id}/runs`
 * ({@see MercureSubscribeTopics::catalogRuns()} — AUD-001: the tenant prefix +
 * `private: true` + the subscriber-JWT claim minted by forTenant() close the
 * cross-tenant leak class).
 *
 * One topic per catalog (not per run): the monitor screen subscribes when the
 * catalog detail opens, so a run started later still streams into the open
 * view. Events carry `run_id` for the FE to demultiplex.
 *
 * Hub failures are logged but never abort the regeneration — the cache write is
 * the source of truth; Mercure is a notification channel.
 */
final class CatalogProgressPublisher
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly string $topicBase,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Per-chunk progress tick. `itemsTotal` is the best-effort expected size
     * (the previous successful run's item count) — null on a first run, in
     * which case `progress_pct`/`eta` stay null and the FE renders an
     * indeterminate bar with a live counter.
     */
    public function progress(CatalogRun $run, int $itemsDone, ?int $itemsTotal, ?int $estimatedSecondsRemaining): void
    {
        $pct = (null !== $itemsTotal && $itemsTotal > 0)
            ? min(100, (int) floor($itemsDone / $itemsTotal * 100))
            : null;

        $this->publish($run, 'progress', [
            'items_done' => $itemsDone,
            'items_total' => $itemsTotal,
            'progress_pct' => $pct,
            'estimated_seconds_remaining' => $estimatedSecondsRemaining,
        ]);
    }

    /**
     * Status transition (pending → running → done / error / cancelled).
     * Drives the monitor pipeline stages + the hub tile badge.
     */
    public function status(CatalogRun $run): void
    {
        $this->publish($run, 'status', [
            'status' => $run->getStatus()->value,
            'page_count' => $run->getPageCount(),
            'byte_size' => $run->getByteSize(),
            'item_count' => $run->getItemCount(),
            'duration_ms' => $run->getDurationMs(),
            'error_message' => $run->getErrorMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(CatalogRun $run, string $eventType, array $payload): void
    {
        $tenant = $run->getTenant();
        if (null === $tenant) {
            // Runs are tenant-stamped on persist; a null here is a
            // misconfiguration. Skip rather than emit an un-scoped topic.
            $this->logger->warning('Catalog progress publish skipped: run has no tenant', [
                'run_id' => $run->getId()->toRfc4122(),
                'event' => $eventType,
            ]);

            return;
        }

        $message = [
            'event' => $eventType,
            'run_id' => $run->getId()->toRfc4122(),
            'catalog_id' => $run->getCatalogProfileId()->toRfc4122(),
            ...$payload,
        ];
        $encoded = json_encode($message, JSON_THROW_ON_ERROR);

        $topic = MercureSubscribeTopics::catalogRuns(
            $tenant->getId(),
            $this->topicBase,
            $run->getCatalogProfileId()->toRfc4122(),
        );

        try {
            $this->hub->publish(new Update($topic, $encoded, private: true));
        } catch (Throwable $error) {
            $this->logger->warning('Catalog progress publish failed', [
                'run_id' => $run->getId()->toRfc4122(),
                'event' => $eventType,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
