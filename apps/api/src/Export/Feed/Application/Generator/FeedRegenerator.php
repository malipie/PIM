<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Generator;

use App\Export\Feed\Application\Delivery\FeedCacheStorage;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Shared\Application\TenantContext;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Regeneration orchestrator (ADR-0023 §6.6, XMLF-P4-01) — the shared core the
 * manual "Regeneruj teraz" endpoint and (later, XMLF-P4-02) the async worker
 * both drive, mirroring how {@see \App\Export\Application\Sync\SyncExportRunner}
 * is shared by the sync export controller and the async job handler.
 *
 * It streams the feed through {@see FeedGenerator} into a local temp file,
 * uploads a successful run to the tenant-scoped cache key
 * (`feeds/<tenant>/<feed>.xml`, {@see FeedCacheStorage}) and records the cache
 * pointer on the {@see FeedProfile} so the public URL (XMLF-P3-05) can serve it.
 *
 * The generator's value source clears the EntityManager between chunks to stay
 * memory-flat (IMP2 reuse), which detaches the profile — we reload a managed
 * instance before recording the cache pointer, the same guard the export job
 * handler uses.
 */
final class FeedRegenerator
{
    public function __construct(
        private readonly FeedGenerator $generator,
        private readonly FeedCacheStorage $cache,
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function regenerate(FeedProfile $profile, FeedRunTrigger $trigger): FeedRun
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new RuntimeException('Feed regeneration requires a tenant context.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pim-feed-');
        if (false === $tempPath) {
            throw new RuntimeException('Unable to allocate a temp file for feed generation.');
        }

        try {
            $run = $this->generator->generate($profile, $tempPath, $trigger);

            if (FeedRunStatus::Done === $run->getStatus()) {
                $key = $this->cacheKey($tenant->getId(), $profile->getId());
                $this->cache->put($key, $tempPath);

                // The streaming value source clears the EM mid-run (IMP2 reuse),
                // detaching $profile — reload a managed instance before saving.
                $managed = $this->feeds->findById($profile->getId()) ?? $profile;
                $size = $run->getFileSizeBytes() ?? (is_file($tempPath) ? (int) filesize($tempPath) : 0);
                $managed->recordCache($run->getId(), $key, $size, $run->getItemCount(), new DateTimeImmutable());
                $this->feeds->save($managed);
            }

            return $run;
        } finally {
            @unlink($tempPath);
        }
    }

    private function cacheKey(Uuid $tenantId, Uuid $feedId): string
    {
        return sprintf('feeds/%s/%s.xml', $tenantId->toRfc4122(), $feedId->toRfc4122());
    }
}
