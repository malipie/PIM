<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Per-feed run history + drill-down + health (XMLF-P4-03, ADR-0023 §6.5) —
 * the read side behind FeedDetail and FeedRunDrilldown: run list, one run's
 * tiles, its per-product FeedRunLog lines ("SKU-123: missing g:gtin —
 * skipped") and the feed health card (syndication counters + mapping
 * coverage). Read-only projections; auto-registered into OpenAPI (ADR-0020).
 */
final class FeedRunHistoryController
{
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedRunRepositoryInterface $runs,
        private readonly FeedRunLogRepositoryInterface $logs,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feeds/{id}/runs', name: 'pim_feeds_runs_list', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(string $id, Request $request): JsonResponse
    {
        $this->assertTenantUser();
        $feed = $this->loadFeedOrFail($id);

        $status = $request->query->get('status');
        $health = \in_array($status, ['success', 'warning', 'error'], true) ? $status : null;
        $cursor = $this->readCursor($request);
        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        $page = $this->runs->findPage($feed->getId(), $health, $cursor, $limit);

        return new JsonResponse([
            'items' => array_map($this->serializeRun(...), $page),
            'next_cursor' => [] !== $page && \count($page) === $limit
                ? $page[\count($page) - 1]->getId()->toRfc4122()
                : null,
        ]);
    }

    #[Route(path: '/api/feeds/{id}/runs/{runId}', name: 'pim_feeds_run_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'runId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function detail(string $id, string $runId): JsonResponse
    {
        $this->assertTenantUser();
        $feed = $this->loadFeedOrFail($id);
        $run = $this->loadRunOrFail($feed, $runId);

        return new JsonResponse($this->serializeRun($run));
    }

    #[Route(path: '/api/feeds/{id}/runs/{runId}/logs', name: 'pim_feeds_run_logs', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'runId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function logs(string $id, string $runId, Request $request): JsonResponse
    {
        $this->assertTenantUser();
        $feed = $this->loadFeedOrFail($id);
        $run = $this->loadRunOrFail($feed, $runId);

        $levelRaw = $request->query->get('level');
        $level = \in_array($levelRaw, ['info', 'warning', 'error'], true) ? $levelRaw : null;
        $cursor = $this->readCursor($request);
        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        $page = $this->logs->findPageByRun($run->getId(), $level, $cursor, $limit);

        return new JsonResponse([
            'items' => array_map(static fn (FeedRunLog $log): array => [
                'id' => $log->getId()->toRfc4122(),
                'level' => $log->getLevel()->value,
                'object_sku' => $log->getObjectSku(),
                'slot' => $log->getSlot(),
                'message' => $log->getMessage(),
                'created_at' => $log->getCreatedAt()->format(DateTimeInterface::ATOM),
            ], $page),
            'next_cursor' => [] !== $page && \count($page) === $limit
                ? $page[\count($page) - 1]->getId()->toRfc4122()
                : null,
        ]);
    }

    #[Route(path: '/api/feeds/{id}/health', name: 'pim_feeds_health', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function health(string $id): JsonResponse
    {
        $this->assertTenantUser();
        $feed = $this->loadFeedOrFail($id);

        $lastRun = $this->runs->findByFeedProfile($feed->getId(), 1)[0] ?? null;

        return new JsonResponse([
            'feed_id' => $feed->getId()->toRfc4122(),
            'status' => $feed->getStatus()->value,
            'items_syndicated' => $feed->getCachedItemCount(),
            'file_size_bytes' => $feed->getCachedFileSize(),
            'cached_at' => $feed->getCachedAt()?->format(DateTimeInterface::ATOM),
            'last_pulled_at' => $feed->getLastPulledAt()?->format(DateTimeInterface::ATOM),
            'schedule_cron' => $feed->getScheduleCron(),
            // The cron scheduler engine is a P4-01 leftover delivered with the
            // worker daemon ticket — until it lands there is no computed
            // next-run timestamp to report.
            'next_run' => null,
            'coverage' => $this->coverage($feed),
            'last_run' => null !== $lastRun ? $this->serializeRun($lastRun) : null,
        ]);
    }

    /**
     * Mapping coverage: how many of the descriptor's item slots have a
     * mapping (the design's "coverage mapped/total" card). Parsed through the
     * FeedDescriptor VO — the authoritative reader for both the flat custom
     * shape and the channel-nested marketplace shapes (Google RSS).
     *
     * @return array{mapped: int, total: int}
     */
    private function coverage(FeedProfile $feed): array
    {
        try {
            $descriptor = FeedDescriptor::fromArray($feed->getDescriptor());
        } catch (InvalidDescriptorException) {
            // Write-time guarded (XMLF-P1-04); an unparsable legacy descriptor
            // degrades the card to 0/0 instead of failing the health read.
            return ['mapped' => 0, 'total' => 0];
        }

        $slots = [];
        foreach ($descriptor->slots as $slot) {
            $slots[$slot->target] = false;
        }
        foreach ($feed->getFieldMappings() as $mapping) {
            $key = \is_string($mapping['slot'] ?? null) ? $mapping['slot'] : null;
            if (null !== $key && \array_key_exists($key, $slots)) {
                $slots[$key] = true;
            }
        }

        return [
            'mapped' => \count(array_filter($slots)),
            'total' => \count($slots),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(FeedRun $run): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'feed_id' => $run->getFeedProfileId()->toRfc4122(),
            'trigger' => $run->getTrigger()->value,
            'status' => $run->getStatus()->value,
            'health' => FeedRunMonitorController::health($run),
            'item_count' => $run->getItemCount(),
            'skipped_count' => $run->getSkippedCount(),
            'warning_count' => $run->getWarningCount(),
            'file_size_bytes' => $run->getFileSizeBytes(),
            'duration_ms' => $run->getDurationMs(),
            'error_message' => $run->getErrorMessage(),
            'started_at' => $run->getStartedAt()->format(DateTimeInterface::ATOM),
            'completed_at' => $run->getCompletedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    private function readCursor(Request $request): ?Uuid
    {
        $raw = $request->query->get('cursor');

        // An invalid cursor serves the first page — stale URLs never 500.
        return (\is_string($raw) && Uuid::isValid($raw)) ? Uuid::fromString($raw) : null;
    }

    private function loadFeedOrFail(string $id): FeedProfile
    {
        $feed = $this->feeds->findById(Uuid::fromString($id));
        if (null === $feed) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        return $feed;
    }

    private function loadRunOrFail(FeedProfile $feed, string $runId): FeedRun
    {
        $run = $this->runs->findById(Uuid::fromString($runId));
        // A run of another feed is a flat 404 — ids are not interchangeable.
        if (!$run instanceof FeedRun || !$run->getFeedProfileId()->equals($feed->getId())) {
            throw new NotFoundHttpException(sprintf('Feed run "%s" was not found.', $runId));
        }

        return $run;
    }

    private function assertTenantUser(): void
    {
        if (!$this->security->getUser() instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        if (null === $this->tenantContext->get()) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }
    }
}
