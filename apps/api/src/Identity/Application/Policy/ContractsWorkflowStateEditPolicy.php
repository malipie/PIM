<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Contracts\Policy\WorkflowStateEditPolicyInterface;
use App\Identity\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * WFL-P1-02 (#2416) — token-resolving adapter over
 * {@see WorkflowStatePolicy} (RBAC-P3-011, dormant until now). Missing
 * or non-domain principal = trusted system path (import/CLI/fixtures)
 * — allowed by design (ADR-0029 pillar 8); every HTTP write endpoint
 * authenticates first, so anonymous requests never reach this branch.
 */
final readonly class ContractsWorkflowStateEditPolicy implements WorkflowStateEditPolicyInterface
{
    public function __construct(
        private Security $security,
        private WorkflowStatePolicy $policy,
    ) {
    }

    public function canEditInState(string $state): bool
    {
        if ('' === $state) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return true;
        }

        return $this->policy->canEditInState($user, $state);
    }

    public function requiresAutoUnpublish(string $state): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->policy->requiresAutoUnpublish($user, $state);
    }
}
