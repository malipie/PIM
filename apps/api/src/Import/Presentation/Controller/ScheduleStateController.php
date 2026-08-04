<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Application\Service\ScheduleDispatcherService;
use App\Import\Domain\Entity\ImportSchedule;
use App\Import\Domain\Repository\ImportScheduleRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-IMP-04 (#502) — toggle + run-now endpoints.
 *
 * Both wrap the dispatcher service; the controller is the HTTP edge.
 */
final class ScheduleStateController
{
    public function __construct(
        private readonly ImportScheduleRepositoryInterface $schedules,
        private readonly ScheduleDispatcherService $dispatcher,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/import-schedules/{id}/toggle',
        name: 'imports_schedule_toggle',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_schedule', action: 'write')]
    public function toggle(string $id): JsonResponse
    {
        $schedule = $this->load($id);
        $schedule->isEnabled() ? $schedule->disable() : $schedule->enable();
        if ($schedule->isEnabled()) {
            $this->dispatcher->computeNextRun($schedule);
        } else {
            $schedule->setNextRun(null);
            $this->schedules->save($schedule);
        }

        return new JsonResponse([
            'id' => $schedule->getId()->toRfc4122(),
            'enabled' => $schedule->isEnabled(),
            'next_run' => $schedule->getNextRun()?->format(DateTimeInterface::RFC3339_EXTENDED),
        ], Response::HTTP_OK);
    }

    #[Route(
        path: '/api/import-schedules/{id}/run-now',
        name: 'imports_schedule_run_now',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_schedule', action: 'write')]
    public function runNow(string $id): JsonResponse
    {
        $schedule = $this->load($id);
        $run = $this->dispatcher->runNow($schedule);

        return new JsonResponse([
            'run_id' => $run->getId()->toRfc4122(),
            'status' => $run->getStatus()->value,
            'triggered_at' => $run->getTriggeredAt()->format(DateTimeInterface::RFC3339_EXTENDED),
            'next_run' => $schedule->getNextRun()?->format(DateTimeInterface::RFC3339_EXTENDED),
        ], Response::HTTP_ACCEPTED);
    }

    private function load(string $rawId): ImportSchedule
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        try {
            $id = Uuid::fromString($rawId);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Import schedule "%s" was not found.', $rawId));
        }
        $schedule = $this->schedules->findById($id);
        if (!$schedule instanceof ImportSchedule) {
            throw new NotFoundHttpException(\sprintf('Import schedule "%s" was not found.', $rawId));
        }
        if ($schedule->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import schedule "%s" was not found.', $rawId));
        }

        return $schedule;
    }
}
