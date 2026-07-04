<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Controller;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Tool\AgentQuickAction;
use App\Agent\Application\Tool\ToolRegistry;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * #2246 — agent capability discovery for the UI entry points (dashboard
 * hero, Cmd+K). Deliberately WITHOUT AgentFeatureGuard::assertEnabled()
 * and without #[RequiresPermission] — unlike the run endpoints, this one
 * exists so the UI can degrade gracefully: it answers 200 with a
 * structured reason instead of an opaque 403 problem document. Nothing
 * leaks: the action list is computed through ToolRegistry::availableFor()
 * (fail-closed RBAC + autonomy), and the reasons expose the same facts
 * the run endpoints' 403s already do.
 */
final readonly class AgentCapabilitiesController
{
    public function __construct(
        private AgentFeatureGuard $featureGuard,
        private ToolRegistry $registry,
        private PermissionCheckerInterface $permissions,
        private TenantContext $tenantContext,
        private Security $security,
    ) {
    }

    #[Route('/api/agent/capabilities', name: 'pim_agent_capabilities', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[NoPermissionRequired(reason: 'Discovery endpoint: reports agent availability (incl. missing agent.bulk_actions) as structured data so the UI degrades gracefully; the action list itself is RBAC-filtered fail-closed via ToolRegistry::availableFor().')]
    public function capabilities(): JsonResponse
    {
        // Machine principals (API keys) are not user-scoped — the agent
        // conversation surface is meaningless for them (same stance as
        // AgentRunController::userId(), reported as data instead of 400).
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            return $this->degraded('no_permission');
        }
        $userId = $user->getId();

        // Permission BEFORE the feature guard: a user without
        // agent.bulk_actions must not learn the tenant's BYOK-key state
        // (the run endpoints deny on #[RequiresPermission] first, too).
        if (!$this->permissions->userHasPermission($userId, 'agent.bulk_actions')) {
            return $this->degraded('no_permission');
        }

        $reason = $this->featureGuard->unavailabilityReason($this->tenant());
        if (null !== $reason) {
            return $this->degraded($reason);
        }

        return new JsonResponse([
            'enabled' => true,
            'reason' => null,
            'actions' => array_map(
                static fn (AgentQuickAction $action): array => $action->toArray(),
                $this->registry->quickActionsFor($userId),
            ),
        ]);
    }

    private function degraded(string $reason): JsonResponse
    {
        return new JsonResponse(['enabled' => false, 'reason' => $reason, 'actions' => []]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('No tenant resolved for this request.');
        }

        return $tenant;
    }
}
