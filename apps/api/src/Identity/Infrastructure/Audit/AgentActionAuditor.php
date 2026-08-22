<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Audit;

use App\Identity\Application\Audit\AuditTenantResolver;
use App\Identity\Contracts\Audit\AgentActionAuditor as AgentActionAuditorContract;
use App\Identity\Domain\Entity\AuditLog;
use App\Identity\Domain\Repository\AuditLogRepositoryInterface;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-07 (#1967) — adapter behind Identity\Contracts\Audit:
 * append-only agent accountability entries in audit_logs
 * (resource_type=agent_run). Mirrors the DataExportAuditor shape;
 * the actor is explicit (agent transitions can run off-request with
 * no security token), IP/UA captured when a request is present.
 */
final readonly class AgentActionAuditor implements AgentActionAuditorContract
{
    public function __construct(
        private AuditLogRepositoryInterface $repository,
        private AuditTenantResolver $auditTenant,
        private RequestStack $requestStack,
    ) {
    }

    public function recordAgentAction(string $action, string $runId, ?string $actorId, array $details): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $entry = new AuditLog(
            id: Uuid::v7(),
            tenantId: $this->auditTenant->resolve()?->getId(),
            userId: null !== $actorId ? Uuid::fromString($actorId) : null,
            superAdminId: null,
            action: $action,
            resourceType: 'agent_run',
            resourceId: $runId,
            oldValue: null,
            newValue: $details,
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
