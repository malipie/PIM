<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Generator;

use App\Export\Catalog\Application\Async\CatalogCancelledException;
use App\Export\Catalog\Application\CatalogRenderService;
use App\Export\Catalog\Application\Delivery\CatalogCacheStorage;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Regeneration orchestrator (ADR-0027, CPDF-P3-01) — the shared core the
 * manual "Generuj teraz" endpoint and the async worker
 * ({@see \App\Export\Catalog\Application\Async\GenerateCatalogHandler}) both
 * drive, the catalog-world mirror of
 * {@see \App\Export\Feed\Application\Generator\FeedRegenerator}.
 *
 * DESIGN DIVERGENCE from the Feed engine: {@see CatalogRenderService} is a PURE
 * renderer — it maps the profile to HTML, renders a PDF binary to a temp path
 * and returns a {@see \App\Export\Catalog\Application\CatalogRenderResult}
 * (counters only). It does NOT own the {@see CatalogRun} lifecycle. So this
 * regenerator owns the run state transitions (markDone / markCancelled /
 * markError) AND the cache pointer on the profile — the pieces the
 * FeedGenerator handled internally.
 *
 * It renders through the service into a local temp file, uploads a successful
 * run to the tenant-scoped cache key (`catalogs/<tenant>/<catalog>.pdf`,
 * {@see CatalogCacheStorage}) and records the cache pointer on the
 * {@see CatalogProfile} so the public URL (CPDF-P3-02) can serve it.
 *
 * EM-clear reload guard: the render's value source pages products keyset-style
 * and clears the EntityManager between chunks to stay memory-flat (IMP2 reuse),
 * which detaches `$profile` and `$run`. This class reloads a managed instance
 * of each (`findById(...) ?? $original`) before saving so the write hits a
 * tracked entity, the same guard the export/feed handlers use.
 *
 * Failures are captured in run state (markError) and returned — NOT re-thrown,
 * mirroring the Feed handler: the terminal state is visible in the monitor
 * pipeline instead of surfacing a 500 on the sync (dev/test) transport.
 */
final class CatalogRegenerator
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CatalogRenderService $renderer,
        private readonly CatalogCacheStorage $cache,
        private readonly CatalogProfileRepositoryInterface $profiles,
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly TenantContext $tenantContext,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param Closure(int): void|null $onChunk progress callback forwarded to the render service;
     *                                         may throw CatalogCancelledException to stop gracefully
     */
    public function regenerate(CatalogProfile $profile, CatalogRun $run, ?Closure $onChunk = null): CatalogRun
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new RuntimeException('Catalog regeneration requires a tenant context.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'pim-catalog-');
        if (false === $temp) {
            throw new RuntimeException('Unable to allocate a temp file for catalog generation.');
        }

        $startedAt = microtime(true);

        try {
            // May throw CatalogCancelledException (propagated from onChunk).
            $result = $this->renderer->render($profile, $temp, $onChunk);

            $key = sprintf(
                'catalogs/%s/%s.pdf',
                $tenant->getId()->toRfc4122(),
                $profile->getId()->toRfc4122(),
            );
            $this->cache->put($key, $temp);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            // The render's value source cleared the EM mid-run (IMP2 reuse),
            // detaching $profile / $run — reload managed instances before saving.
            $managedProfile = $this->profiles->findById($profile->getId()) ?? $profile;
            $managedProfile->recordCache($key, $result->byteSize, $result->pageCount, new DateTimeImmutable());
            $this->profiles->save($managedProfile);

            $managedRun = $this->runs->findById($run->getId()) ?? $run;
            $managedRun->markDone($result->pageCount, $result->byteSize, $result->itemCount, $durationMs);
            $this->runs->save($managedRun);

            // Peak-memory telemetry (CPDF-P6-04, decision A): feeds the worker
            // memory guardrail (§3.10 Prometheus alert) with a per-run number.
            $this->logger->info('Catalog render completed', [
                'run_id' => $managedRun->getId()->toRfc4122(),
                'items' => $result->itemCount,
                'pages' => $result->pageCount,
                'duration_ms' => $durationMs,
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            ]);

            return $managedRun;
        } catch (CatalogCancelledException) {
            // The cancel endpoint already persisted `cancelled`; record it on the
            // managed run so the terminal publish reads a graceful stop.
            $managedRun = $this->runs->findById($run->getId()) ?? $run;
            $managedRun->markCancelled();
            $this->runs->save($managedRun);

            return $managedRun;
        } catch (Throwable $error) {
            $managedRun = $this->runs->findById($run->getId()) ?? $run;
            $managedRun->markError($error->getMessage());
            $this->runs->save($managedRun);

            return $managedRun;
        } finally {
            @unlink($temp);
        }
    }
}
