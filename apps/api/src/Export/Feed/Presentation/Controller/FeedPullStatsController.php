<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedPullStatsRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Pull telemetry read side (XMLF-P3-06) — the numbers behind the hub KPI
 * "Pobrania · 24h", the sparkline and the monitor's "last pulled" stamp.
 *
 * Per-feed: `GET /api/feeds/{id}/pull-stats`. Tenant-wide (the hub KPI strip
 * sums every feed): `GET /api/feeds/pull-stats`. Auto-registered into OpenAPI
 * by CustomRouteOpenApiFactory (ADR-0020).
 */
final class FeedPullStatsController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedPullStatsRepositoryInterface $pullStats,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feeds/pull-stats', name: 'pim_feeds_pull_stats_global', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function global(): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $aggregate = $this->pullStats->aggregate($tenant, null, new DateTimeImmutable());

        return new JsonResponse([
            'pulls_24h' => $aggregate->pulls24h,
            'spark' => $aggregate->spark,
        ]);
    }

    #[Route(path: '/api/feeds/{id}/pull-stats', name: 'pim_feeds_pull_stats', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function perFeed(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $feed = $this->loadOrFail($id);
        $aggregate = $this->pullStats->aggregate($tenant, $feed->getId(), new DateTimeImmutable());

        return new JsonResponse([
            'feed_id' => $feed->getId()->toRfc4122(),
            'pulls_24h' => $aggregate->pulls24h,
            'last_pulled_at' => $feed->getLastPulledAt()?->format(DateTimeInterface::ATOM),
            'spark' => $aggregate->spark,
        ]);
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

    private function loadOrFail(string $id): FeedProfile
    {
        $feed = $this->feeds->findById(Uuid::fromString($id));
        if (null === $feed) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        return $feed;
    }
}
