<?php

declare(strict_types=1);

namespace App\Dashboard\Presentation\Controller;

use App\Dashboard\Application\Query\DashboardSummaryQuery;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DASH-04 (#2255, ADR-0026) — the dashboard KPI + completeness aggregate
 * behind the Pulpit widgets: one call replaces the FE's nine count probes
 * (4× totalItems + 5× completeness[gte]).
 */
final class DashboardSummaryController
{
    public function __construct(
        private readonly DashboardSummaryQuery $query,
        private readonly CurrentUserProvider $currentUser,
        private readonly PermissionCheckerInterface $permissions,
    ) {
    }

    #[Route(
        path: '/api/dashboard/summary',
        name: 'pim_dashboard_summary',
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function __invoke(): JsonResponse
    {
        $userId = $this->currentUser->userId();
        if (null === $userId || null === $this->currentUser->tenant()) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        // #2831 — `products.view` opens the dashboard, but the per-channel
        // breakdown is channel data and needs its own code. Deciding here
        // rather than in the UI matters twice: the payload stops carrying
        // rows the caller may not read, and the query skips the per-channel
        // aggregate entirely instead of computing it to be thrown away.
        $includeChannels = $this->permissions->userHasPermission($userId, 'channel.read');

        return new JsonResponse($this->query->summary($userId, $includeChannels), Response::HTTP_OK);
    }
}
