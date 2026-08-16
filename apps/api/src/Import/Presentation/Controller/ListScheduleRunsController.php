<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Domain\Entity\ImportSchedule;
use App\Import\Domain\Entity\ImportScheduleRun;
use App\Import\Domain\Repository\ImportScheduleRepositoryInterface;
use App\Import\Domain\Repository\ImportScheduleRunRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-IMP-04 (#502) — schedule audit drawer feed.
 */
final class ListScheduleRunsController
{
    #[Route(
        path: '/api/import-schedules/{id}/runs',
        name: 'imports_schedule_runs',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'import_schedule', action: 'read', anyOf: [
        'import_schedule.read',
        'imports.view_own',
        'imports.view_all',
    ])]
    public function __invoke(
        string $id,
        ImportScheduleRepositoryInterface $schedules,
        ImportScheduleRunRepositoryInterface $runs,
        CurrentUserProvider $currentUser,
    ): JsonResponse {
        $userId = $currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        try {
            $scheduleId = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Import schedule "%s" was not found.', $id));
        }
        $schedule = $schedules->findById($scheduleId);
        if (!$schedule instanceof ImportSchedule) {
            throw new NotFoundHttpException(\sprintf('Import schedule "%s" was not found.', $id));
        }
        if ($schedule->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import schedule "%s" was not found.', $id));
        }

        $items = array_map(
            static fn (ImportScheduleRun $r): array => [
                'id' => $r->getId()->toRfc4122(),
                'triggered_at' => $r->getTriggeredAt()->format(DateTimeInterface::RFC3339_EXTENDED),
                'status' => $r->getStatus()->value,
                'duration_ms' => $r->getDurationMs(),
                'session_id' => $r->getSessionId()?->toRfc4122(),
                'error_message' => $r->getErrorMessage(),
            ],
            $runs->findByScheduleId($scheduleId, 50),
        );

        return new JsonResponse([
            'member' => $items,
            'totalItems' => \count($items),
        ], Response::HTTP_OK);
    }
}
