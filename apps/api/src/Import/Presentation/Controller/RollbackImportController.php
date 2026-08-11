<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Application\Service\ImportRollbackService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Message\ImportRollbackMessage;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Messenger\Stamp\TenantStamp;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * IMP-05 (#446) — wizard results screen "Wycofaj import" CTA.
 *
 * #2818 — the endpoint QUEUES the rollback and answers 202. It used to do the
 * work inline, which put any session over roughly 700 objects out of reach: the
 * request died at the 30-second budget, the transaction unwound, and the
 * operator got an error page for a job the system was perfectly capable of
 * doing — just not in one request. Undoing an import is the same size of job as
 * running one, and imports have been queued since IMP-04.
 *
 * The response carries `rolling_back` and the session id; progress arrives on
 * the session's Mercure topic and is persisted on the session, so a refresh
 * does not lose it.
 */
final class RollbackImportController
{
    public function __construct(
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly ImportRollbackService $rollbackService,
        private readonly CurrentUserProvider $currentUser,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/{id}/rollback',
        name: 'imports_rollback',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'admin')]
    public function __invoke(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);
        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new ConflictHttpException('Import session has no tenant assignment.');
        }

        try {
            // Claim the session BEFORE dispatching: the status flip is what
            // stops a second click (or a second operator) from queueing a
            // rollback over the one already running, and it is committed by the
            // time the worker can pick the message up.
            $session->markRollbackStarted(new DateTimeImmutable());
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $this->sessions->save($session);

        $this->bus->dispatch(
            new ImportRollbackMessage(
                importSessionId: $session->getId(),
                tenantId: $tenant->getId(),
            ),
            [new TenantStamp($tenant->getId())],
        );

        // The sync transport (dev/test) runs the handler during dispatch, so
        // re-read rather than reporting the status we wrote a moment ago.
        $reload = $this->sessions->findById($session->getId()) ?? $session;

        return new JsonResponse($this->serialize($reload), Response::HTTP_ACCEPTED);
    }

    /**
     * #2818 — ask a running rollback to stop.
     *
     * It stops after the chunk it is on and the session STAYS `rolling_back`
     * with its checkpoint: part of the catalogue is undone and part is not, and
     * that has to be visible rather than dressed up as either state. POSTing to
     * the rollback endpoint again continues from the checkpoint.
     */
    #[Route(
        path: '/api/import-sessions/{id}/rollback/cancel',
        name: 'imports_rollback_cancel',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'admin')]
    public function cancel(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);

        try {
            $session->requestRollbackCancel();
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $this->sessions->save($session);

        return new JsonResponse($this->serialize($session), Response::HTTP_ACCEPTED);
    }

    /**
     * #2818 — continue a rollback that stopped short.
     *
     * A separate verb from starting one, because the session is already
     * `rolling_back` and the start guard refuses it — deliberately, since that
     * guard is what keeps two workers off one undo-log. Resuming is the
     * operator saying "carry on from the checkpoint", after a cancel or after
     * a worker died mid-run.
     */
    #[Route(
        path: '/api/import-sessions/{id}/rollback/resume',
        name: 'imports_rollback_resume',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'admin')]
    public function resume(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);
        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new ConflictHttpException('Import session has no tenant assignment.');
        }

        try {
            $session->resumeRollback();
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $this->sessions->save($session);

        $this->bus->dispatch(
            new ImportRollbackMessage(
                importSessionId: $session->getId(),
                tenantId: $tenant->getId(),
            ),
            [new TenantStamp($tenant->getId())],
        );

        $reload = $this->sessions->findById($session->getId()) ?? $session;

        return new JsonResponse($this->serialize($reload), Response::HTTP_ACCEPTED);
    }

    /**
     * IMP2-2.4 — pre-rollback preview: what "Wycofaj import" would do (objects
     * deleted, values restored/removed, manual edits left untouched). Read-only.
     */
    #[Route(
        path: '/api/import-sessions/{id}/rollback-preview',
        name: 'imports_rollback_preview',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'admin')]
    public function preview(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);

        return new JsonResponse($this->rollbackService->preview($session), Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ImportSession $session): array
    {
        $report = $session->getRollbackReport() ?? [];
        $count = static fn (string $key): int => \is_int($report[$key] ?? null) ? $report[$key] : 0;

        return [
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
            'rolled_back_at' => $session->getRolledBackAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'progress_updated_at' => $session->getProgressUpdatedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            // The counters stay at the top level, where every consumer of this
            // endpoint has read them since IMP2-2.4. What changed with #2818 is
            // WHEN they are final: while the status is `rolling_back` they are a
            // running total of committed work, and the status is what says so.
            'deleted_objects' => $count('deleted_objects'),
            'deleted_object_values' => $count('deleted_values'),
            'restored_values' => $count('restored_values'),
            'removed_values' => $count('removed_values'),
            'skipped_manual_edits' => $count('skipped_manual_edits'),
            'skipped_superseded' => $count('skipped_superseded'),
            // Progress of the queued run: which phase, and how far through the
            // affected objects it is.
            'objects_done' => $count('objects_done'),
            'objects_total' => $count('objects_total'),
            'phase' => \is_string($report['phase'] ?? null) ? $report['phase'] : null,
            'cancel_requested' => true === ($report['cancel_requested'] ?? false),
            'stopped_reason' => \is_string($report['stopped_reason'] ?? null) ? $report['stopped_reason'] : null,
        ];
    }

    private function loadOwned(string $rawId): ImportSession
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        try {
            $id = Uuid::fromString($rawId);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Import session "%s" was not found.', $rawId));
        }

        $session = $this->sessions->findById($id);
        if (!$session instanceof ImportSession || $session->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import session "%s" was not found.', $rawId));
        }

        return $session;
    }
}
