<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Message\ImportRunMessage;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Messenger\Stamp\TenantStamp;
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
 * Wizard controls — pause / resume / cancel for an in-flight import.
 *
 * State changes are authoritative on the {@see ImportSession} entity;
 * the worker (`ImportRunHandler`) inspects the row between batches in
 * a follow-up to honour pause/cancel mid-run. In MVP the operator
 * sees the response immediately and the running batch finishes the
 * current chunk before stopping.
 */
final class ImportSessionStateController
{
    public function __construct(
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly CurrentUserProvider $currentUser,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/{id}/pause',
        name: 'imports_pause',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'write')]
    public function pause(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);
        try {
            $session->markPaused();
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $this->sessions->save($session);

        return $this->respond($session);
    }

    #[Route(
        path: '/api/import-sessions/{id}/resume',
        name: 'imports_resume',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'write')]
    public function resume(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);
        try {
            $session->markRunning();
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $this->sessions->save($session);

        // IMP2-2.3 — re-dispatch the run so the worker picks the session back
        // up from its checkpoint. Just flipping the status (the MVP behaviour)
        // was a no-op once the handler had already exited on pause.
        $tenant = $session->getTenant();
        if ($tenant instanceof Tenant) {
            $this->bus->dispatch(
                new ImportRunMessage(
                    importSessionId: $session->getId(),
                    tenantId: $tenant->getId(),
                ),
                [new TenantStamp($tenant->getId())],
            );
        }

        return $this->respond($session);
    }

    #[Route(
        path: '/api/import-sessions/{id}/cancel',
        name: 'imports_cancel',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'write')]
    public function cancel(string $id): JsonResponse
    {
        $session = $this->loadOwned($id);
        try {
            $session->markCancelled();
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        $this->sessions->save($session);

        return $this->respond($session);
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

    private function respond(ImportSession $session): JsonResponse
    {
        return new JsonResponse([
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
        ], Response::HTTP_OK);
    }
}
