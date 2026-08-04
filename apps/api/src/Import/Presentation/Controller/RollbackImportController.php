<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Application\Service\ImportRollbackService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * IMP-05 (#446) — wizard results screen "Wycofaj import" CTA.
 *
 * Owner-only, 24h window. The handler does the heavy lifting; this
 * controller is the HTTP edge: parse id, load + check ownership,
 * delegate, surface a clear error if the window expired or the
 * session isn't rollbackable.
 */
final class RollbackImportController
{
    public function __construct(
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly ImportRollbackService $rollbackService,
        private readonly CurrentUserProvider $currentUser,
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

        try {
            $result = $this->rollbackService->rollback($session);
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }

        $reload = $this->sessions->findById($session->getId()) ?? $session;

        return new JsonResponse([
            'id' => $reload->getId()->toRfc4122(),
            'status' => $reload->getStatus()->value,
            'rolled_back_at' => $reload->getRolledBackAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'deleted_objects' => $result['deletedObjects'],
            'deleted_object_values' => $result['deletedValues'],
            // IMP2-2.4 — rollback v2 also reverses overwrites on existing objects.
            'restored_values' => $result['restoredValues'],
            'removed_values' => $result['removedValues'],
            'skipped_manual_edits' => $result['skippedManualEdits'],
            // Values a LATER import owns now — left intact, not clobbered.
            'skipped_superseded' => $result['skippedSuperseded'],
        ], Response::HTTP_OK);
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
