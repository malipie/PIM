<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Backup\Domain\Entity\Backup;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only endpoint surfacing the live counts + status the wizard's
 * progress / results screens poll between Mercure events.
 */
final class GetImportSessionController
{
    public function __construct(
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/{id}',
        name: 'imports_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'read')]
    public function __invoke(string $id): JsonResponse
    {
        $session = $this->loadSession($id);

        return new JsonResponse([
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
            'file_name' => $session->getFileName(),
            'total_rows' => $session->getTotalRows(),
            'success_count' => $session->getSuccessCount(),
            'error_count' => $session->getErrorCount(),
            'updated_count' => $session->getUpdatedCount(),
            'skipped_count' => $session->getSkippedCount(),
            'mode' => $session->getMode()->value,
            'images_downloaded' => $session->getImagesDownloaded(),
            'images_failed' => $session->getImagesFailed(),
            // IMP2-1.13 (#1476) follow-up — ZIP source metadata in the contract,
            // null for non-ZIP imports.
            'zip_file_name' => $session->getZipFileName(),
            'zip_file_size_bytes' => $session->getZipFileSizeBytes(),
            'started_at' => $session->getStartedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $session->getCompletedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'rollback_until' => $session->getRollbackUntil()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'rolled_back_at' => $session->getRolledBackAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'error_message' => $session->getErrorMessage(),
            // IMP2-2.10 (#1486) — the pre-import backup linked at start, or null.
            'backup' => self::serializeBackup($session->getBackupSnapshot()),
        ], Response::HTTP_OK);
    }

    /**
     * @return array{id: string, status: string, started_at: string}|null
     */
    private static function serializeBackup(?Backup $backup): ?array
    {
        if (!$backup instanceof Backup) {
            return null;
        }

        return [
            'id' => $backup->getId()->toRfc4122(),
            'status' => $backup->getStatus()->value,
            'started_at' => $backup->getStartedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    private function loadSession(string $rawId): ImportSession
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
        if (!$session instanceof ImportSession) {
            throw new NotFoundHttpException(\sprintf('Import session "%s" was not found.', $rawId));
        }
        if ($session->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import session "%s" was not found.', $rawId));
        }

        return $session;
    }
}
