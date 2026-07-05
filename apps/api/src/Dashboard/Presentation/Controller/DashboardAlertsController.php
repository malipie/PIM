<?php

declare(strict_types=1);

namespace App\Dashboard\Presentation\Controller;

use App\Dashboard\Application\Query\AlertFeedAggregator;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * DASH-09 (#2265, ADR-0026) — the action-center feed: on-the-fly alert
 * aggregation over the four status tables + the completeness-drop
 * detector, and the idempotent per-fingerprint acknowledge.
 */
final class DashboardAlertsController
{
    private const int MAX_LIMIT = 50;

    /** Deterministic fingerprint shape produced by AlertFeedAggregator. */
    private const string FINGERPRINT_PATTERN = '/^[a-z_]+:[A-Za-z0-9:._-]{1,140}$/';

    public function __construct(
        private readonly AlertFeedAggregator $aggregator,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/dashboard/alerts',
        name: 'pim_dashboard_alerts',
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function alerts(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId();

        $window = $request->query->get('window', '7d');
        if (!\array_key_exists($window, AlertFeedAggregator::WINDOWS)) {
            throw new BadRequestHttpException('window must be one of: 1d, 7d, 30d.');
        }
        $limit = (int) $request->query->get('limit', '5');
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new BadRequestHttpException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        return new JsonResponse($this->aggregator->alerts($userId, $window, $limit), Response::HTTP_OK);
    }

    #[Route(
        path: '/api/dashboard/alerts/{fingerprint}/ack',
        name: 'pim_dashboard_alert_ack',
        methods: ['POST'],
        requirements: ['fingerprint' => '[A-Za-z0-9:._-]+'],
    )]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function acknowledge(string $fingerprint): JsonResponse
    {
        $userId = $this->authenticatedUserId();

        if (1 !== preg_match(self::FINGERPRINT_PATTERN, $fingerprint)) {
            throw new BadRequestHttpException('Malformed alert fingerprint.');
        }

        $this->aggregator->acknowledge($fingerprint, $userId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function authenticatedUserId(): Uuid
    {
        $userId = $this->currentUser->userId();
        if (null === $userId || null === $this->currentUser->tenant()) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        return $userId;
    }
}
