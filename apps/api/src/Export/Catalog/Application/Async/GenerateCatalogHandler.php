<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Async;

use App\Export\Catalog\Application\Generator\CatalogRegenerator;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Enum\CatalogRunStatus;
use App\Export\Catalog\Domain\Message\RunCatalogMessage;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Shared\Application\AbstractBatchHandler;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * CPDF-P3-01 — async PDF catalog generation worker, the catalog-world mirror of
 * {@see \App\Export\Feed\Application\Async\FeedRunHandler}. The flow:
 *
 *   1. Load the persisted CatalogRun (state survives retries) + idempotency
 *      guard: only a `pending` run is processed — a duplicate delivery or a
 *      pre-cancelled run is skipped with an info log, never an exception.
 *   2. Rebind TenantContext from the run's tenant (the request listener never
 *      fires on a worker boot; the messenger middleware already restored the
 *      RLS GUC from the TenantAwareMessage). Like FeedRunHandler, the tenant is
 *      read straight off the persisted run's relation — the message's tenantId
 *      only feeds the rebinding middleware.
 *   3. markRunning + publish `status` so the monitor pipeline lights up.
 *   4. Drive {@see CatalogRegenerator} — the same engine the sync endpoint uses
 *      (render → cache upload → cache pointer on the profile) — with a per-chunk
 *      callback that publishes progress + ETA and polls the PERSISTED run status
 *      for cancellation (the in-memory entity is stale inside the long loop; the
 *      cancel endpoint writes straight to the row).
 *   5. Publish the terminal `status` (done / error / cancelled) off the run the
 *      regenerator returns (it owns the terminal transition).
 *
 * Memory safety: the render's value source pages products keyset-style and
 * clears the EntityManager every chunk (IMP2 reuse), so a large catalog stays
 * flat; this class extends {@see AbstractBatchHandler} so any future batch write
 * it grows funnels through flushAndClear() (CLAUDE.md §3.10).
 *
 * Failures are captured in run state (markError inside the regenerator) and
 * published — NOT re-thrown. Mirrors the feed handler: in dev/test the transport
 * is sync://, so a re-throw would surface a 500 on the dispatching request even
 * though the failure is already recorded and visible in the monitor.
 * Cancellation is a graceful stop, not a failure.
 */
#[AsMessageHandler]
final class GenerateCatalogHandler extends AbstractBatchHandler
{
    private readonly LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly CatalogProfileRepositoryInterface $profiles,
        private readonly CatalogRegenerator $regenerator,
        private readonly CatalogProgressPublisher $progress,
        private readonly TenantContext $tenantContext,
        ?LoggerInterface $logger = null,
        int $batchSize = 1000,
    ) {
        parent::__construct($entityManager, $batchSize);
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(RunCatalogMessage $message): void
    {
        $run = $this->runs->findById($message->catalogRunId);
        if (!$run instanceof CatalogRun) {
            $this->logger->info('Catalog run handler skipped — run not found', [
                'run_id' => $message->catalogRunId->toRfc4122(),
            ]);

            return;
        }

        // Idempotency guard — duplicate delivery (sync transport + retries)
        // or a run cancelled before the worker picked it up.
        if (CatalogRunStatus::Pending !== $run->getStatus()) {
            $this->logger->info('Catalog run handler skipped — run not pending', [
                'run_id' => $run->getId()->toRfc4122(),
                'status' => $run->getStatus()->value,
            ]);

            return;
        }

        $tenant = $run->getTenant();
        if (!$tenant instanceof Tenant) {
            $run->markError('Catalog run has no tenant assignment.');
            $this->runs->save($run);

            return;
        }
        $this->tenantContext->set($tenant);

        $profile = $this->profiles->findById($run->getCatalogProfileId());
        if (!$profile instanceof CatalogProfile) {
            $run->markError('Catalog profile no longer exists.');
            $this->runs->save($run);
            $this->progress->status($run);

            return;
        }

        $run->markRunning();
        $this->runs->save($run);
        $this->progress->status($run);

        $runId = $run->getId();
        // No cached assortment size on the profile, so total/ETA stay null and
        // the FE renders an indeterminate progress bar with a live counter until
        // a first run establishes the size (parity with a feed's first run).
        $onChunk = function (int $processed) use ($runId, $run): void {
            $this->progress->progress($run, $processed, null, null);

            // The in-memory run is stale inside the long render loop; the cancel
            // endpoint writes straight to the row — poll the persisted status.
            $current = $this->runs->findById($runId);
            if (null !== $current && CatalogRunStatus::Cancelled === $current->getStatus()) {
                throw new CatalogCancelledException('Catalog generation cancelled by the user.');
            }
        };

        // The regenerator owns the terminal transition (done / cancelled / error)
        // and returns the managed run — publish its terminal status. It never
        // re-throws; failures are recorded on the run.
        $terminal = $this->regenerator->regenerate($profile, $run, $onChunk);
        $this->progress->status($terminal);

        if (CatalogRunStatus::Cancelled === $terminal->getStatus()) {
            $this->logger->info('Catalog run cancelled by user', [
                'run_id' => $terminal->getId()->toRfc4122(),
            ]);
        } elseif (CatalogRunStatus::Error === $terminal->getStatus()) {
            $this->logger->error('Catalog run failed', [
                'run_id' => $terminal->getId()->toRfc4122(),
                'error' => $terminal->getErrorMessage(),
            ]);
        }
    }
}
