<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedStatus;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Global feed monitor (XMLF-P4-03, ADR-0023 §6.5) — the read side behind the
 * FeedMonitor screen: the tenant-wide run history (RunsTable) and the health
 * KPI tiles (Regeneracje 24h / Produkty syndykowane / Pominięte 24h /
 * Błędy 24h). Read-only projections; auto-registered into OpenAPI (ADR-0020).
 *
 * Runs are keyset-paginated over the UUIDv7 id (== started_at order); the
 * `cursor` query param round-trips the last item's id. `status` filters the
 * DISPLAY health: success (done, 0 warnings) | warning (done, >0) | error.
 */
final class FeedRunMonitorController
{
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly FeedRunRepositoryInterface $runs,
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feed-runs', name: 'pim_feed_runs_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant();
        [$health, $cursor, $limit] = $this->readListParams($request);

        $page = $this->runs->findPage(null, $health, $cursor, $limit);

        // Names for the global table — one indexed load for the whole page
        // (a tenant has dozens of feeds, not thousands).
        $profiles = [];
        foreach ($this->feeds->findByTenant($tenant) as $profile) {
            $profiles[$profile->getId()->toRfc4122()] = $profile;
        }

        $items = array_map(
            fn (FeedRun $run): array => $this->serializeRun($run, $profiles[$run->getFeedProfileId()->toRfc4122()] ?? null),
            $page,
        );

        return new JsonResponse([
            'items' => $items,
            'next_cursor' => [] !== $page && \count($page) === $limit
                ? $page[\count($page) - 1]->getId()->toRfc4122()
                : null,
        ]);
    }

    #[Route(path: '/api/feed-runs/kpi', name: 'pim_feed_runs_kpi', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function kpi(): JsonResponse
    {
        $tenant = $this->resolveTenant();

        $kpi = $this->runs->kpi24h($tenant, new DateTimeImmutable());

        // Current syndication snapshot: what the public URLs serve right now
        // (design tile "Produkty syndykowane" — inventory, not a 24h flow).
        $itemsSyndicated = 0;
        $lastRegeneratedAt = null;
        $statusCounts = ['active' => 0, 'paused' => 0, 'error' => 0];
        foreach ($this->feeds->findByTenant($tenant) as $profile) {
            $itemsSyndicated += $profile->getCachedItemCount() ?? 0;
            $cachedAt = $profile->getCachedAt();
            if (null !== $cachedAt && (null === $lastRegeneratedAt || $cachedAt > $lastRegeneratedAt)) {
                $lastRegeneratedAt = $cachedAt;
            }
            $key = match ($profile->getStatus()) {
                FeedStatus::Active => 'active',
                FeedStatus::Paused => 'paused',
                FeedStatus::Error => 'error',
            };
            ++$statusCounts[$key];
        }

        return new JsonResponse([
            ...$kpi,
            'items_syndicated' => $itemsSyndicated,
            'last_regenerated_at' => $lastRegeneratedAt?->format(DateTimeInterface::ATOM),
            'feeds' => $statusCounts,
        ]);
    }

    /**
     * @return array{0: string|null, 1: Uuid|null, 2: int}
     */
    private function readListParams(Request $request): array
    {
        $status = $request->query->get('status');
        $health = \in_array($status, ['success', 'warning', 'error'], true) ? $status : null;

        $cursorRaw = $request->query->get('cursor');
        // An invalid cursor serves the first page — stale URLs never 500.
        $cursor = (\is_string($cursorRaw) && Uuid::isValid($cursorRaw)) ? Uuid::fromString($cursorRaw) : null;

        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        return [$health, $cursor, $limit];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(FeedRun $run, ?FeedProfile $profile): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'feed_id' => $run->getFeedProfileId()->toRfc4122(),
            'feed_name' => $profile?->getName(),
            'feed_code' => $profile?->getCode(),
            'template_kind' => $profile?->getTemplateKind()->value,
            'trigger' => $run->getTrigger()->value,
            'status' => $run->getStatus()->value,
            'health' => self::health($run),
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

    /**
     * Display status for the monitor chips — `done` splits by warnings.
     */
    public static function health(FeedRun $run): string
    {
        return match ($run->getStatus()) {
            FeedRunStatus::Done => $run->getWarningCount() > 0 ? 'warning' : 'success',
            FeedRunStatus::Error => 'error',
            FeedRunStatus::Pending => 'pending',
            FeedRunStatus::Running => 'running',
            FeedRunStatus::Cancelled => 'cancelled',
        };
    }

    private function resolveTenant(): Tenant
    {
        if (!$this->security->getUser() instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        return $tenant;
    }
}
