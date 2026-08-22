<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Audit;

use App\Identity\Application\Audit\AuditTenantResolver;
use App\Identity\Contracts\Audit\DataExportAuditor as DataExportAuditorContract;
use App\Identity\Domain\Entity\AuditLog;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\AuditLogRepositoryInterface;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * AUD-052 (W2-11) — writes the dedicated `data_export` audit entry.
 *
 * Unlike the generic {@see AuditLogListener} (which records the GET request's
 * method + permission outcome with old/new null), this entry carries
 * `action = 'data_export'`, the session id as `resource_id`, and the export
 * scope (entity type / format / row count) in `new_value` so the compliance
 * trail answers "who exported what, when?".
 *
 * Actor + tenant are resolved from the security token, mirroring the listener;
 * the IP / user-agent are pulled from the current request when available.
 */
final readonly class DataExportAuditor implements DataExportAuditorContract
{
    public function __construct(
        private AuditLogRepositoryInterface $repository,
        private Security $security,
        private AuditTenantResolver $auditTenant,
        private RequestStack $requestStack,
    ) {
    }

    public function recordExport(string $sessionId, array $scope): void
    {
        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $request = $this->requestStack->getCurrentRequest();

        $entry = new AuditLog(
            id: Uuid::v7(),
            tenantId: $this->auditTenant->resolve()?->getId(),
            userId: $userId,
            superAdminId: null,
            action: 'data_export',
            resourceType: 'export_session',
            resourceId: $sessionId,
            oldValue: null,
            newValue: $scope,
            permissionCheckResult: 'granted',
            crossTenantAccess: false,
            specialFlags: [],
            ipAddress: $request?->getClientIp(),
            userAgent: $request?->headers->get('User-Agent'),
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->save($entry);
    }
}
