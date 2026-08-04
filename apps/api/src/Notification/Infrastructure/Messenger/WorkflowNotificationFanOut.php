<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Messenger;

use App\Catalog\Contracts\Event\ObjectApproved;
use App\Catalog\Contracts\Event\ObjectRejected;
use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Catalog\Contracts\Event\ObjectUnpublishRequested;
use App\Identity\Contracts\Policy\PermissionGranteesInterface;
use App\Notification\Contracts\NotifierPort;
use App\Shared\Application\TenantContext;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Contracts\TransitionLogPort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-02 (#2421) — workflow notification fan-out, riding the same
 * post-flush Messenger pipeline as MercurePublisher (tenant context is
 * rebound from the TenantAwareMessage by the middleware stack):
 *
 *   - submit_for_review → every holder of `workflow.approve_reject`
 *     EXCEPT the submitter (no self-notification about own work),
 *   - approve / reject  → the author of the latest submit (from the
 *     transition log), decision comment attached. Deliberately no
 *     actor exclusion here: a reviewer deciding on their own
 *     submission still gets the record (single-user tenants would
 *     otherwise never see the loop working).
 */
final readonly class WorkflowNotificationFanOut
{
    public const string TYPE_SUBMITTED = 'workflow.submitted_for_review';
    public const string TYPE_APPROVED = 'workflow.approved';
    public const string TYPE_REJECTED = 'workflow.rejected';
    public const string TYPE_UNPUBLISH_REQUESTED = 'workflow.unpublish_requested';

    public function __construct(
        private NotifierPort $notifier,
        private PermissionGranteesInterface $grantees,
        private TransitionLogPort $transitionLog,
        private TenantContext $tenantContext,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[AsMessageHandler]
    public function onSubmittedForReview(ObjectSubmittedForReview $event): void
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            $this->logDroppedNotification(self::TYPE_SUBMITTED, $event->objectId);

            return;
        }

        $submitter = $this->submitAuthor($event->objectId);
        $recipients = [];
        foreach ($this->grantees->userIdsWithPermission($tenant, 'workflow.approve_reject') as $userId) {
            if (null !== $submitter && $userId->equals($submitter)) {
                continue;
            }
            $recipients[] = $userId;
        }

        $this->notifier->notifyUsers($recipients, self::TYPE_SUBMITTED, [
            'object_id' => $event->objectId->toRfc4122(),
            'comment' => $event->comment,
        ]);
    }

    #[AsMessageHandler]
    public function onApproved(ObjectApproved $event): void
    {
        $this->notifyAuthor($event->objectId, self::TYPE_APPROVED, $event->comment);
    }

    #[AsMessageHandler]
    public function onRejected(ObjectRejected $event): void
    {
        $this->notifyAuthor($event->objectId, self::TYPE_REJECTED, $event->comment);
    }

    #[AsMessageHandler]
    public function onUnpublishRequested(ObjectUnpublishRequested $event): void
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            $this->logDroppedNotification(self::TYPE_UNPUBLISH_REQUESTED, $event->objectId);

            return;
        }

        $recipients = [];
        foreach ($this->grantees->userIdsWithPermission($tenant, 'workflow.transition.unpublish') as $userId) {
            if (null !== $event->requesterId && $userId->equals($event->requesterId)) {
                continue;
            }
            $recipients[] = $userId;
        }

        $this->notifier->notifyUsers($recipients, self::TYPE_UNPUBLISH_REQUESTED, [
            'object_id' => $event->objectId->toRfc4122(),
            'comment' => $event->comment,
        ]);
    }

    private function notifyAuthor(Uuid $objectId, string $type, ?string $comment): void
    {
        $author = $this->submitAuthor($objectId);
        if (null === $author) {
            return;
        }

        $this->notifier->notifyUsers([$author], $type, [
            'object_id' => $objectId->toRfc4122(),
            'comment' => $comment,
        ]);
    }

    private function submitAuthor(Uuid $objectId): ?Uuid
    {
        return $this->transitionLog
            ->latestForObject($objectId, ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW)
            ?->actorUserId;
    }

    /**
     * #2734 — the message is TenantAware, so a missing context on the worker is
     * an infrastructure fault, not a normal case. The notification is dropped
     * either way (recipients cannot be resolved without a tenant), but it must
     * never disappear silently — "the reviewer never got notified" is
     * undebuggable without this trace.
     */
    private function logDroppedNotification(string $type, Uuid $objectId): void
    {
        $this->logger->warning('Workflow notification dropped — no tenant context on the worker.', [
            'type' => $type,
            'object_id' => $objectId->toRfc4122(),
        ]);
    }
}
