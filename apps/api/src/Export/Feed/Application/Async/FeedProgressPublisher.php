<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Async;

use App\Export\Feed\Domain\Entity\FeedRun;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * XMLF-P4-02 (#1926) — Mercure SSE publisher for feed regeneration runs.
 *
 * Publishes lifecycle events to the tenant-scoped, private per-feed topic
 * `tenant/{tid}/feeds/{feed_id}/runs` ({@see MercureSubscribeTopics::feedRuns()}
 * — AUD-001: the tenant prefix + `private: true` + the subscriber-JWT claim
 * minted by forTenant() close the cross-tenant leak class).
 *
 * One topic per feed (not per run): the monitor screen subscribes when the
 * feed detail opens, so a run started later still streams into the open view.
 * Events carry `run_id` for the FE to demultiplex.
 *
 * Hub failures are logged but never abort the regeneration — the MinIO cache
 * write is the source of truth; Mercure is a notification channel (mirrors
 * {@see \App\Export\Application\Async\ExportProgressPublisher}).
 */
final class FeedProgressPublisher
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
    public function progress(FeedRun $run, int $itemsDone, ?int $itemsTotal, ?int $estimatedSecondsRemaining): void
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
    public function status(FeedRun $run): void
    {
        $this->publish($run, 'status', [
            'status' => $run->getStatus()->value,
            'item_count' => $run->getItemCount(),
            'skipped_count' => $run->getSkippedCount(),
            'warning_count' => $run->getWarningCount(),
            'error_message' => $run->getErrorMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(FeedRun $run, string $eventType, array $payload): void
    {
        $tenant = $run->getTenant();
        if (null === $tenant) {
            // Runs are tenant-stamped on persist; a null here is a
            // misconfiguration. Skip rather than emit an un-scoped topic.
            $this->logger->warning('Feed progress publish skipped: run has no tenant', [
                'run_id' => $run->getId()->toRfc4122(),
                'event' => $eventType,
            ]);

            return;
        }

        $message = [
            'event' => $eventType,
            'run_id' => $run->getId()->toRfc4122(),
            'feed_id' => $run->getFeedProfileId()->toRfc4122(),
            ...$payload,
        ];
        $encoded = json_encode($message, JSON_THROW_ON_ERROR);

        $topic = MercureSubscribeTopics::feedRuns(
            $tenant->getId(),
            $this->topicBase,
            $run->getFeedProfileId()->toRfc4122(),
        );

        try {
            $this->hub->publish(new Update($topic, $encoded, private: true));
        } catch (Throwable $error) {
            $this->logger->warning('Feed progress publish failed', [
                'run_id' => $run->getId()->toRfc4122(),
                'event' => $eventType,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
