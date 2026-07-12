<?php

declare(strict_types=1);

namespace App\Workflow\Domain\Repository;

use App\Workflow\Contracts\WorkflowTaskStatus;
use App\Workflow\Contracts\WorkflowTaskType;
use App\Workflow\Domain\Entity\WorkflowTask;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P4-01 (#2428) — task persistence. `save()` flushes immediately:
 * the automation writes from post-flush Messenger handlers where no
 * ambient flush follows.
 */
interface WorkflowTaskRepositoryInterface
{
    public function save(WorkflowTask $task): void;

    public function find(Uuid $id): ?WorkflowTask;

    /**
     * Newest-first page (UUIDv7 cursor). `$mineUserId` matches direct
     * assignment OR membership in the assignee role (both role paths).
     * `$mineReviewerTaskTypes` widens "mine" for a caller entitled to act
     * on reviewer work — a `workflow.approve_reject` holder sees every
     * task of these types regardless of who it is routed to (role OR a
     * specific user), so the inbox matches the notification fan-out and
     * survives a configurable approver (#2513, generalizes #2495).
     *
     * @param list<WorkflowTaskType> $mineReviewerTaskTypes
     *
     * @return list<WorkflowTask>
     */
    public function page(
        int $limit,
        ?Uuid $before = null,
        ?WorkflowTaskStatus $status = null,
        ?Uuid $objectId = null,
        ?Uuid $mineUserId = null,
        ?DateTimeImmutable $dueBefore = null,
        array $mineReviewerTaskTypes = [],
    ): array;

    /**
     * @return list<WorkflowTask> every OPEN task of the type for the object
     */
    public function openTasksFor(Uuid $objectId, WorkflowTaskType $type): array;
}
