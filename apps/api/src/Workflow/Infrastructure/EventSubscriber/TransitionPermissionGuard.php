<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\EventSubscriber;

use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Workflow\Application\ActingUserContext;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * WFL-P1-01 (#2415) — RBAC guard map for the `object_editorial` state
 * machine (ADR-0029 pillar 4). Guards are DATA (permission codes), not
 * config expressions — the Pimcore lesson: rule changes must not need
 * a deploy, and the M5 tenant definition builder reuses the same codes.
 *
 * The blocker carries the missing permission code so 409 responses and
 * the discovery endpoint (GET …/workflow) tell the user WHICH grant is
 * missing, per the PRD §3.8 UX ("nie generyczne 403").
 *
 * Actor resolution: security token first, then ActingUserContext
 * (Messenger workers acting within the initiating user's permissions).
 * No actor at all = trusted system path (CLI/fixtures) — allowed; HTTP
 * cannot reach that branch because the endpoints require auth.
 */
final readonly class TransitionPermissionGuard implements EventSubscriberInterface
{
    /**
     * Transition -> required permission code (PRD-PIM-rbac §3.8).
     *
     * @var array<string, string>
     */
    private const array PERMISSION_MAP = [
        ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW => 'products.edit',
        ObjectEditorialWorkflow::TRANSITION_PUBLISH => 'workflow.approve_reject',
        ObjectEditorialWorkflow::TRANSITION_APPROVE => 'workflow.approve_reject',
        ObjectEditorialWorkflow::TRANSITION_REJECT => 'workflow.approve_reject',
        ObjectEditorialWorkflow::TRANSITION_ARCHIVE => 'workflow.approve_reject',
        ObjectEditorialWorkflow::TRANSITION_RESTORE => 'workflow.approve_reject',
        ObjectEditorialWorkflow::TRANSITION_UNPUBLISH => 'workflow.transition.unpublish',
    ];

    public function __construct(
        private CurrentUserProvider $currentUser,
        private ActingUserContext $actingUser,
        private PermissionCheckerInterface $permissions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.'.ObjectEditorialWorkflow::NAME.'.guard' => 'onGuard',
        ];
    }

    public function onGuard(GuardEvent $event): void
    {
        $transition = $event->getTransition()->getName();

        // Tenant definitions (WFL-P5-01) carry the permission as
        // per-transition METADATA; the static YAML machine falls back
        // to the constant map. No metadata + no map entry = an open
        // transition (the builder may deliberately leave one ungated).
        $metadataPermission = $event->getMetadata('permission', $event->getTransition());
        $permission = \is_string($metadataPermission)
            ? $metadataPermission
            : (self::PERMISSION_MAP[$transition] ?? null);
        if (null === $permission) {
            return;
        }

        $userId = $this->currentUser->userId() ?? $this->actingUser->userId();
        if (null === $userId) {
            return;
        }

        if ($this->permissions->userHasPermission($userId, $permission)) {
            return;
        }

        $event->addTransitionBlocker(new TransitionBlocker(
            \sprintf('Missing permission "%s" required for transition "%s".', $permission, $transition),
            $permission,
        ));
    }
}
