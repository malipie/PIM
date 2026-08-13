<?php

declare(strict_types=1);

namespace App\Dashboard\Presentation\Controller;

use App\Dashboard\Application\Query\DashboardActivityQuery;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DASH-07 (#2261, ADR-0026) — team-activity aggregates behind the
 * "Tempo pracy zespołu" dashboard card: the daily added/modified series
 * and the most-edited products ranking.
 */
final class DashboardActivityController
{
    private const int MAX_TOP_EDITED_LIMIT = 20;

    public function __construct(
        private readonly DashboardActivityQuery $query,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/dashboard/activity',
        name: 'pim_dashboard_activity',
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function activity(Request $request): JsonResponse
    {
        $this->assertAuthenticated();
        $range = $this->rangeOf($request);

        return new JsonResponse($this->query->activity($range), Response::HTTP_OK);
    }

    #[Route(
        path: '/api/dashboard/top-edited',
        name: 'pim_dashboard_top_edited',
        methods: ['GET'],
    )]
    // #2831 — this endpoint names WHO edited what, so `products.view` is the
    // wrong gate: a Catalog Manager holds it while its audit reach is
    // `audit.view_own`. Cross-user attribution needs the cross-user code.
    #[RequiresPermission(module: 'audit', action: 'view_cross_user')]
    public function topEdited(Request $request): JsonResponse
    {
        $this->assertAuthenticated();
        $range = $this->rangeOf($request);
        $limit = (int) $request->query->get('limit', '6');
        if ($limit < 1 || $limit > self::MAX_TOP_EDITED_LIMIT) {
            throw new BadRequestHttpException(
                \sprintf('limit must be between 1 and %d.', self::MAX_TOP_EDITED_LIMIT),
            );
        }

        return new JsonResponse($this->query->topEdited($range, $limit), Response::HTTP_OK);
    }

    private function rangeOf(Request $request): string
    {
        $range = $request->query->get('range', '30d');
        if (!\array_key_exists($range, DashboardActivityQuery::RANGES)) {
            throw new BadRequestHttpException('range must be one of: 7d, 30d, 90d.');
        }

        return $range;
    }

    private function assertAuthenticated(): void
    {
        if (null === $this->currentUser->userId() || null === $this->currentUser->tenant()) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
    }
}
