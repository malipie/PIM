<?php

declare(strict_types=1);

namespace App\Backup\Presentation\Controller;

use App\Backup\Domain\Entity\Backup;
use App\Backup\Domain\Repository\BackupRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Status polling endpoint for the wizard's Step 4 backup checkbox.
 * The frontend polls every 5 s while `status` ∈ {pending, running}
 * and unblocks the "Uruchom import" button on `completed` / surfaces
 * the error on `failed`.
 */
final class GetBackupController
{
    public function __construct(
        private readonly BackupRepositoryInterface $backups,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/backups/{id}',
        name: 'backups_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    // #2881 — see TriggerBackupController: backups are a tenant-admin
    // surface, so `settings.tenant.manage` answers alongside the platform code.
    #[RequiresPermission(module: 'backup', action: 'read', anyOf: [
        'backup.read',
        'settings.tenant.manage',
        'platform.tenants.manage',
    ])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Backup "%s" was not found.', $id));
        }

        $backup = $this->backups->findById($uuid);
        if (!$backup instanceof Backup) {
            throw new NotFoundHttpException(\sprintf('Backup "%s" was not found.', $id));
        }

        if (!$this->security->isGranted('READ', $backup)) {
            throw new AccessDeniedHttpException();
        }

        return new JsonResponse([
            'id' => $backup->getId()->toRfc4122(),
            'status' => $backup->getStatus()->value,
            'triggered_by_action' => $backup->getTriggeredByAction()->value,
            'pgbackrest_label' => $backup->getPgbackrestLabel(),
            'size_bytes' => $backup->getSizeBytes(),
            'started_at' => $backup->getStartedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $backup->getCompletedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'error_message' => $backup->getErrorMessage(),
        ], Response::HTTP_OK);
    }
}
