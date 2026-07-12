<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Messenger;

use App\Catalog\Contracts\Event\ObjectApproved;
use App\Catalog\Contracts\Event\ObjectRejected;
use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Catalog\Contracts\Event\ObjectUnpublished;
use App\Catalog\Contracts\Event\ObjectUnpublishRequested;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Workflow\Contracts\EditorialWorkflowProviderInterface;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Contracts\TaskAssignee;
use App\Workflow\Contracts\TransitionLogPort;
use App\Workflow\Contracts\WorkflowTaskType;
use App\Workflow\Domain\Entity\WorkflowTask;
use App\Workflow\Domain\Repository\WorkflowTaskRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P4-02 (#2429) — the task loop nobody has to run by hand
 * (Akeneo/inRiver pattern: a workflow step IS a task):
 *
 *   - submit_for_review     → open `review` task for the canonical
 *                             reviewer role (PRD persona `approver`),
 *   - approve / reject      → close open review tasks (resolved by the
 *                             decision maker); reject additionally
 *                             opens a `fix` task for the submit author
 *                             with the reviewer comment,
 *   - unpublish             → close open request_unpublish tasks,
 *   - unpublish requested   → open `request_unpublish` task for the
 *                             approver role.
 *
 * Idempotent: an object never carries two OPEN tasks of one type
 * (a re-submit reuses the existing review task). Tasks never send
 * their own notification — the P2-02 fan-out already pinged the same
 * audience about the underlying event (the fix task's author IS the
 * `workflow.rejected` recipient), and the task itself lands in the
 * assignee's inbox (WFL-P4-03).
 */
final readonly class WorkflowTaskAutomation
{
    public function __construct(
        private WorkflowTaskRepositoryInterface $tasks,
        private TransitionLogPort $transitionLog,
        private CurrentUserProvider $currentUser,
        private EditorialWorkflowProviderInterface $workflows,
    ) {
    }

    #[AsMessageHandler]
    public function onSubmittedForReview(ObjectSubmittedForReview $event): void
    {
        if ([] !== $this->tasks->openTasksFor($event->objectId, WorkflowTaskType::Review)) {
            return;
        }

        $assignee = $this->resolveReviewer($event->objectTypeId);
        $this->tasks->save(new WorkflowTask(
            title: 'Review request',
            type: WorkflowTaskType::Review,
            objectId: $event->objectId,
            assigneeUserId: $assignee->userId !== null ? Uuid::fromString($assignee->userId) : null,
            assigneeRoleCode: $assignee->roleCode,
            createdBy: $this->currentUser->userId(),
            comment: $event->comment,
            context: ['object_id' => $event->objectId->toRfc4122()],
        ));
    }

    #[AsMessageHandler]
    public function onApproved(ObjectApproved $event): void
    {
        $this->closeOpen($event->objectId, WorkflowTaskType::Review);
    }

    #[AsMessageHandler]
    public function onRejected(ObjectRejected $event): void
    {
        $this->closeOpen($event->objectId, WorkflowTaskType::Review);

        $author = $this->transitionLog
            ->latestForObject($event->objectId, ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW)
            ?->actorUserId;
        if (null === $author) {
            return;
        }

        if ([] !== $this->tasks->openTasksFor($event->objectId, WorkflowTaskType::Fix)) {
            return;
        }

        $this->tasks->save(new WorkflowTask(
            title: 'Fix after rejection',
            type: WorkflowTaskType::Fix,
            objectId: $event->objectId,
            assigneeUserId: $author,
            createdBy: $this->currentUser->userId(),
            comment: $event->comment,
            context: ['object_id' => $event->objectId->toRfc4122()],
        ));
    }

    #[AsMessageHandler]
    public function onUnpublished(ObjectUnpublished $event): void
    {
        $this->closeOpen($event->objectId, WorkflowTaskType::RequestUnpublish);
    }

    #[AsMessageHandler]
    public function onUnpublishRequested(ObjectUnpublishRequested $event): void
    {
        if ([] !== $this->tasks->openTasksFor($event->objectId, WorkflowTaskType::RequestUnpublish)) {
            return;
        }

        $assignee = $this->resolveReviewer($event->objectTypeId);
        $this->tasks->save(new WorkflowTask(
            title: 'Unpublish request',
            type: WorkflowTaskType::RequestUnpublish,
            objectId: $event->objectId,
            assigneeUserId: $assignee->userId !== null ? Uuid::fromString($assignee->userId) : null,
            assigneeRoleCode: $assignee->roleCode,
            createdBy: $event->requesterId,
            comment: $event->comment,
            context: ['object_id' => $event->objectId->toRfc4122()],
        ));
    }

    /**
     * #2513 — the configured reviewer for this ObjectType's definition,
     * or the built-in reviewer role when custom definitions are off / no
     * reviewer is set. A definition that points at a user routes the task
     * straight to that person's inbox.
     */
    private function resolveReviewer(?Uuid $objectTypeId): TaskAssignee
    {
        return $this->workflows->reviewerFor($objectTypeId?->toRfc4122())
            ?? TaskAssignee::role(ObjectEditorialWorkflow::REVIEWER_ROLE);
    }

    private function closeOpen(Uuid $objectId, WorkflowTaskType $type): void
    {
        foreach ($this->tasks->openTasksFor($objectId, $type) as $task) {
            $task->complete($this->currentUser->userId());
            $this->tasks->save($task);
        }
    }
}
