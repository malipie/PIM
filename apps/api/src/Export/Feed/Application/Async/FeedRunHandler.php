<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Async;

use App\Export\Feed\Application\Generator\FeedRegenerator;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Message\RunFeedMessage;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Shared\Application\AbstractBatchHandler;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * XMLF-P4-02 (#1926) — async feed regeneration worker, the feed-world mirror
 * of {@see \App\Export\Application\Async\ExportJobHandler}. The flow:
 *
 *   1. Load the persisted FeedRun (state survives retries) + idempotency
 *      guard: only a `pending` run is processed — a duplicate delivery or a
 *      pre-cancelled run is skipped with an info log, never an exception.
 *   2. Rebind TenantContext from the run's tenant (the request listener never
 *      fires on a worker boot; the messenger middleware already restored the
 *      RLS GUC from the TenantAwareMessage).
 *   3. markRunning + publish `status` so the monitor pipeline lights up.
 *   4. Drive {@see FeedRegenerator} — the exact engine the sync endpoint uses
 *      (generate → MinIO cache upload → cache pointer on the profile) — with
 *      a per-chunk callback that publishes progress + ETA and polls the
 *      PERSISTED run status for cancellation (the in-memory entity is stale
 *      inside the long loop; the cancel endpoint writes straight to the row).
 *   5. Publish the terminal `status` (done / error / cancelled).
 *
 * Memory safety: the generator's value source pages products keyset-style and
 * clears the EntityManager every chunk (IMP2 reuse), so a 50k-SKU feed stays
 * flat; this class extends {@see AbstractBatchHandler} so any future batch
 * write it grows funnels through flushAndClear() (CLAUDE.md §3.10).
 *
 * Failures are captured in run state (markError inside the generator) and
 * published — NOT re-thrown. Mirrors the export handler: in dev/test the
 * transport is sync://, so a re-throw would surface a 500 on the dispatching
 * request even though the failure is already recorded and visible in the
 * monitor. Cancellation is a graceful stop, not a failure.
 */
#[AsMessageHandler]
final class FeedRunHandler extends AbstractBatchHandler
{
    private readonly LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly FeedRunRepositoryInterface $runs,
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedRegenerator $regenerator,
        private readonly FeedProgressPublisher $progress,
        private readonly TenantContext $tenantContext,
        private readonly Connection $connection,
        ?LoggerInterface $logger = null,
        int $batchSize = 1000,
    ) {
        parent::__construct($entityManager, $batchSize);
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(RunFeedMessage $message): void
    {
        $run = $this->runs->findById($message->feedRunId);
        if (!$run instanceof FeedRun) {
            return;
        }

        // Idempotency guard — duplicate delivery (sync transport + retries)
        // or a run cancelled before the worker picked it up.
        if (FeedRunStatus::Pending !== $run->getStatus()) {
            $this->logger->info('Feed run handler skipped — run not pending', [
                'run_id' => $run->getId()->toRfc4122(),
                'status' => $run->getStatus()->value,
            ]);

            return;
        }

        $tenant = $run->getTenant();
        if (!$tenant instanceof Tenant) {
            $run->markError('Feed run has no tenant assignment.');
            $this->runs->save($run);

            return;
        }
        $this->tenantContext->set($tenant);

        $profile = $this->feeds->findById($run->getFeedProfileId());
        if (!$profile instanceof FeedProfile) {
            $run->markError('Feed profile no longer exists.');
            $this->runs->save($run);
            $this->progress->status($run);

            return;
        }

        $run->markRunning();
        $this->runs->save($run);
        $this->progress->status($run);

        // Best-effort expected size: the previous successful run's item count.
        // Null on a first run — the FE renders an indeterminate bar then.
        $itemsTotal = $profile->getCachedItemCount();
        $startedAt = microtime(true);
        $onChunk = function (int $processed) use ($run, $itemsTotal, $startedAt): void {
            $elapsed = microtime(true) - $startedAt;
            $rate = $elapsed > 0 ? $processed / $elapsed : 0.0;
            $eta = (null !== $itemsTotal && $rate > 0)
                ? (int) ceil(max(0, $itemsTotal - $processed) / $rate)
                : null;
            $this->progress->progress($run, $processed, $itemsTotal, $eta);

            // tenant-safe: per-row read keyed by primary key (run id resolved through the tenant-scoped repository above; RLS GUC active on the worker session)
            $statusNow = $this->connection->fetchOne(
                'SELECT status FROM feed_runs WHERE id = :id',
                ['id' => $run->getId()->toRfc4122()],
            );
            if (FeedRunStatus::Cancelled->value === $statusNow) {
                throw new FeedCancelledException('Feed regeneration cancelled by the user.');
            }
        };

        try {
            $this->regenerator->regenerate($profile, $run->getTrigger(), $run, $onChunk);
            // The value source cleared the EM mid-run (detaching $run) —
            // reload before the terminal publish reads counters.
            $run = $this->reload($message) ?? $run;
            $this->progress->status($run);
        } catch (FeedCancelledException) {
            // The cancel endpoint already persisted the terminal status;
            // notify subscribers so the pipeline drops to "cancelled" live.
            $run = $this->reload($message) ?? $run;
            $this->progress->status($run);
            $this->logger->info('Feed run cancelled by user', [
                'run_id' => $run->getId()->toRfc4122(),
            ]);
        } catch (Throwable $error) {
            $run = $this->reload($message) ?? $run;
            if (FeedRunStatus::Done === $run->getStatus()) {
                // Post-flight failure (e.g. MinIO upload after a completed
                // generation): the run is done but the cache pointer was not
                // refreshed — the public URL keeps serving the PREVIOUS
                // artifact. Keep `done`, log the smell loudly.
                $this->logger->error('Feed run post-flight failure on already-done run', [
                    'run_id' => $run->getId()->toRfc4122(),
                    'error' => $error->getMessage(),
                ]);
            } elseif (FeedRunStatus::Error !== $run->getStatus()) {
                // The generator marks its own failures; this covers throws
                // outside its guarded section (temp alloc, config parse).
                $run->markError($error->getMessage());
                $this->runs->save($run);
            }
            $this->progress->status($run);
            $this->logger->error('Feed run failed', [
                'run_id' => $run->getId()->toRfc4122(),
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function reload(RunFeedMessage $message): ?FeedRun
    {
        return $this->runs->findById($message->feedRunId);
    }
}
