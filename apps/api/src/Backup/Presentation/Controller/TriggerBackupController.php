<?php

declare(strict_types=1);

namespace App\Backup\Presentation\Controller;

use App\Backup\Domain\Entity\Backup;
use App\Backup\Domain\Enum\BackupTriggerAction;
use App\Backup\Domain\Message\BackupSnapshotMessage;
use App\Backup\Domain\Repository\BackupRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Shared\Application\TenantContext;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * IMP-06 (#447) — wizard Step 4 "Utwórz backup" checkbox + admin
 * Settings page trigger.
 *
 * Tied to `IS_GRANTED('backup','write')` — the `backup:write`
 * permission lives only on the super_admin role today (RbacMatrix +
 * IMP-02 — Catalog Manager has read but not write). The 1/h/tenant
 * sliding-window limiter prevents accidental double-clicks from
 * saturating the disk.
 */
final class TriggerBackupController
{
    public function __construct(
        private readonly BackupRepositoryInterface $backups,
        private readonly MessageBusInterface $bus,
        private readonly RateLimiterFactoryInterface $backupTriggerLimiter,
        private readonly Security $security,
        private readonly CurrentUserProvider $currentUser,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        path: '/api/backups',
        name: 'backups_trigger',
        methods: ['POST'],
    )]
    // #2881 — `settings.tenant.manage`, not only `platform.tenants.manage`
    // as the ticket first suggested. The pre-import backup checkbox in the
    // wizard is a tenant-admin action; the platform code belongs to the
    // operator panel and no tenant Owner holds it, which would have left
    // that checkbox dead for every panel-provisioned tenant.
    #[RequiresPermission(module: 'backup', action: 'write', anyOf: [
        'backup.write',
        'settings.tenant.manage',
        'platform.tenants.manage',
    ])]
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $this->currentUser->userId();
        $tenant = $this->currentUser->tenant();
        if (null === $userId || null === $tenant) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        if (!$this->security->isGranted('WRITE', Backup::class)) {
            throw new AccessDeniedHttpException();
        }
        $this->tenantContext->set($tenant);

        $limiter = $this->backupTriggerLimiter->create($tenant->getId()->toRfc4122());
        $reservation = $limiter->consume();
        if (!$reservation->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $reservation->getRetryAfter()->getTimestamp() - time(),
                'Rate limit reached: max 1 backup per hour per tenant.',
            );
        }

        $payload = json_decode($request->getContent(), true);
        $rawAction = \is_array($payload) ? ($payload['triggered_by_action'] ?? 'manual') : 'manual';
        if (!\is_string($rawAction)) {
            throw new BadRequestHttpException('"triggered_by_action" must be a string.');
        }
        $action = BackupTriggerAction::tryFrom($rawAction);
        if (null === $action) {
            throw new BadRequestHttpException(\sprintf(
                'Unsupported triggered_by_action "%s". Allowed: manual, pre_import, scheduled.',
                $rawAction,
            ));
        }

        $backup = new Backup(
            triggeredByUserId: $userId,
            triggeredByAction: $action,
        );
        $this->backups->save($backup);

        $this->bus->dispatch(new BackupSnapshotMessage(
            backupId: $backup->getId(),
            tenantId: $tenant->getId(),
        ));

        $reload = $this->backups->findById($backup->getId()) ?? $backup;

        return new JsonResponse([
            'id' => $reload->getId()->toRfc4122(),
            'status' => $reload->getStatus()->value,
            'triggered_by_action' => $reload->getTriggeredByAction()->value,
            'started_at' => $reload->getStartedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
        ], Response::HTTP_ACCEPTED);
    }
}
