<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Audit;

use App\Identity\Application\Audit\AuditLogRequestMapper;
use App\Identity\Application\CurrentTenantProvider;
use App\Identity\Domain\Entity\AuditLog;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\AuditLogRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-013 (#676) — `kernel.response` listener writing an audit_logs
 * row per request, capturing permission-check outcome and request
 * metadata per PRD §4.3.
 *
 * The listener decides:
 *
 *   - `action`            — request method + route name fallback,
 *   - `resource_type`     — route's `_route` attribute (controller
 *                           target),
 *   - `resource_id`       — `id` / `slug` from route attributes when
 *                           the request was a single-resource hit,
 *   - `permission_check_result`:
 *      - `granted`   for 2xx,
 *      - `denied`    for 403,
 *      - `n_a`       for 401 (auth missing — separate concern) and
 *                    non-auth paths (public endpoints carrying
 *                    `#[NoPermissionRequired]`),
 *      - `super_admin_bypass` reserved for the cross-tenant flow
 *                    (RBAC-P3-014 #677 sets it via SuperAdminContext).
 *
 * The listener stays **dormant on paths it cannot attribute** — see
 * {@see AuditLogRequestMapper::ignoredPathPrefixes()} for the list.
 *
 * Synchronous write per request: simple and correct; async via Symfony
 * Messenger is a profiling-driven follow-up (Phase 6 #720). The dh-auditor
 * bundle already runs sync DB writes per request without issue.
 *
 * Tenant attribution (#2976) reads {@see TenantContext} first and falls
 * back to {@see CurrentTenantProvider}. The two can diverge, and the
 * divergence is not cosmetic: `audit_logs` runs under FORCE ROW LEVEL
 * SECURITY, and the GUC the policy reads is set by
 * {@see \App\Identity\Infrastructure\Doctrine\RlsContextListener} from
 * TenantContext — not from the principal. A route that binds the tenant
 * without a principal (the signed, session-less
 * {@see \App\Export\Catalog\Presentation\Controller\CatalogPullController}
 * and its feed counterpart) therefore produced a row with `tenant_id =
 * NULL` while the GUC named a tenant, which the WITH CHECK clause rejects:
 * `new row violates row-level security policy for table "audit_logs"` —
 * a 500 on every catalog PDF pull. Sourcing the row from the same place
 * as the GUC makes the two agree by construction. The provider fallback
 * covers flows that authenticate WITHIN the request (login), where the
 * context is still empty at kernel.response but the GUC is empty too, so
 * the pre-context-safe policy lets the row through.
 *
 * old_value / new_value payloads are NOT populated here — they require
 * Doctrine entity-change diffing, which is the `dh-auditor` bundle's
 * existing job for domain entities. This listener captures the
 * permission-check decision, not the entity diff.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse')]
final readonly class AuditLogListener
{
    public function __construct(
        private AuditLogRepositoryInterface $repository,
        private Security $security,
        private CurrentTenantProvider $tenantProvider,
        private TenantContext $tenantContext,
        private AuditLogRequestMapper $mapper,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->mapper->shouldSkip($request->getPathInfo())) {
            return;
        }

        $permissionCheckResult = $this->mapper->resolvePermissionCheckResult(
            $event->getResponse()->getStatusCode(),
        );

        $routeAttribute = $request->attributes->get('_route');
        $route = \is_string($routeAttribute) && '' !== $routeAttribute ? $routeAttribute : $request->getPathInfo();
        $resourceId = $this->mapper->resolveResourceId($request->attributes->all());

        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;

        $entry = new AuditLog(
            id: Uuid::v7(),
            tenantId: $this->resolveTenant()?->getId(),
            userId: $userId,
            superAdminId: null,
            action: $request->getMethod(),
            resourceType: $route,
            resourceId: $resourceId,
            oldValue: null,
            newValue: null,
            permissionCheckResult: $permissionCheckResult,
            crossTenantAccess: false,
            specialFlags: [],
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->save($entry);
    }

    /**
     * The tenant the request actually ran under — the same source the RLS
     * GUC is set from, so the audit row can never contradict the policy.
     */
    private function resolveTenant(): ?Tenant
    {
        $bound = $this->tenantContext->get();
        if ($bound instanceof Tenant) {
            return $bound;
        }

        return $this->tenantProvider->getCurrent();
    }
}
