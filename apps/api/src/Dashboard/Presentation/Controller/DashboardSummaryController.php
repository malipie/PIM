<?php

declare(strict_types=1);

namespace App\Dashboard\Presentation\Controller;

use App\Dashboard\Application\Query\DashboardSummaryQuery;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
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
        if (null === $this->currentUser->userId() || null === $this->currentUser->tenant()) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        return new JsonResponse($this->query->summary(), Response::HTTP_OK);
    }
}
