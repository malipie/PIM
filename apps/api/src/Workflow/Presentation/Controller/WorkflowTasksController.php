<?php

declare(strict_types=1);

namespace App\Workflow\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Contracts\WorkflowTaskStatus;
use App\Workflow\Contracts\WorkflowTaskType;
use App\Workflow\Domain\Entity\WorkflowTask;
use App\Workflow\Domain\Repository\WorkflowTaskRepositoryInterface;
use App\Workflow\Infrastructure\Doctrine\DoctrineWorkflowTaskRepository;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P4-01 (#2428) — workflow tasks surface: cursor-paged list with
 * mine/status/object/due filters, manual creation and the
 * complete/cancel/reassign actions. Tasks are collaborative (PRD
 * §3.7): everyone with workflow.view reads them; resolving needs to be
 * the assignee (direct or via role membership) or a
 * workflow.approve_reject holder.
 */
final class WorkflowTasksController
{
    private const string UUID_REQUIREMENT = '[0-9a-fA-F-]{36}';
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly WorkflowTaskRepositoryInterface $tasks,
        private readonly DoctrineWorkflowTaskRepository $taskQueries,
        private readonly CurrentUserProvider $currentUser,
        private readonly PermissionCheckerInterface $permissions,
    ) {
    }

    #[Route(path: '/api/workflow/tasks', name: 'workflow_tasks_list', methods: ['GET'])]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function list(Request $request): JsonResponse
    {
        $limit = \min(self::MAX_LIMIT, \max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        $before = null;
        $beforeRaw = $request->query->getString('before');
        if ('' !== $beforeRaw) {
            if (!Uuid::isValid($beforeRaw)) {
                throw new BadRequestHttpException('The "before" cursor must be a task id (UUID).');
            }
            $before = Uuid::fromString($beforeRaw);
        }

        $status = null;
        $statusRaw = $request->query->getString('status');
        if ('' !== $statusRaw) {
            $status = WorkflowTaskStatus::tryFrom($statusRaw)
                ?? throw new BadRequestHttpException('Unknown task status filter.');
        }

        $objectId = null;
        $objectRaw = $request->query->getString('object_id');
        if ('' !== $objectRaw) {
            if (!Uuid::isValid($objectRaw)) {
                throw new BadRequestHttpException('object_id must be a UUID.');
            }
            $objectId = Uuid::fromString($objectRaw);
        }

        $dueBefore = null;
        $dueRaw = $request->query->getString('due_before');
        if ('' !== $dueRaw) {
            $parsedDue = DateTimeImmutable::createFromFormat('Y-m-d', $dueRaw);
            if (false === $parsedDue) {
                throw new BadRequestHttpException('due_before must be YYYY-MM-DD.');
            }
            $dueBefore = $parsedDue;
        }

        $mine = $request->query->getBoolean('mine') ? $this->requireUserId() : null;

        // Review / request-unpublish tasks are assigned to the reviewer
        // role; a caller who holds workflow.approve_reject can act on them
        // even without being a member of that role, so widen "mine" to
        // match the notification fan-out audience (#2495).
        $mineExtraRoleCodes = null !== $mine
            && $this->permissions->userHasPermission($mine, ObjectEditorialWorkflow::PERMISSION_APPROVE_REJECT)
                ? [ObjectEditorialWorkflow::REVIEWER_ROLE]
                : [];

        $rows = $this->tasks->page(
            $limit + 1,
            $before,
            $status,
            $objectId,
            $mine,
            $dueBefore,
            $mineExtraRoleCodes,
        );
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        return new JsonResponse([
            'items' => \array_map(self::serialize(...), $rows),
            'next_cursor' => $hasMore && [] !== $rows ? $rows[\count($rows) - 1]->getId()->toRfc4122() : null,
        ]);
    }

    #[Route(path: '/api/workflow/tasks', name: 'workflow_tasks_create', methods: ['POST'])]
    #[RequiresPermission(module: 'products', action: 'edit')]
    public function create(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = \json_decode($request->getContent(), true) ?? [];

        $title = $body['title'] ?? null;
        if (!\is_string($title) || '' === \trim($title) || \mb_strlen($title) > 255) {
            throw new BadRequestHttpException('title is required (max 255 chars).');
        }

        $assigneeUserId = null;
        if (\is_string($body['assignee_user_id'] ?? null) && Uuid::isValid($body['assignee_user_id'])) {
            $assigneeUserId = Uuid::fromString($body['assignee_user_id']);
        }
        $assigneeRoleCode = \is_string($body['assignee_role_code'] ?? null) ? $body['assignee_role_code'] : null;
        if (null !== $assigneeUserId && null !== $assigneeRoleCode) {
            throw new BadRequestHttpException('Assign a user OR a role, not both.');
        }

        $objectId = null;
        if (\is_string($body['object_id'] ?? null) && Uuid::isValid($body['object_id'])) {
            $objectId = Uuid::fromString($body['object_id']);
        }

        $dueDate = null;
        if (\is_string($body['due_date'] ?? null)) {
            $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $body['due_date']);
            if (false === $parsedDate) {
                throw new BadRequestHttpException('due_date must be YYYY-MM-DD.');
            }
            $dueDate = $parsedDate;
        }

        $comment = \is_string($body['comment'] ?? null) ? \trim($body['comment']) : null;

        $task = new WorkflowTask(
            title: \trim($title),
            type: WorkflowTaskType::Custom,
            objectId: $objectId,
            assigneeUserId: $assigneeUserId,
            assigneeRoleCode: $assigneeRoleCode,
            dueDate: $dueDate,
            createdBy: $this->currentUser->userId(),
            comment: '' !== $comment ? $comment : null,
        );
        $this->tasks->save($task);

        return new JsonResponse(self::serialize($task), 201);
    }

    #[Route(
        path: '/api/workflow/tasks/{id}',
        name: 'workflow_tasks_update',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['PATCH'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function update(string $id, Request $request): JsonResponse
    {
        $task = $this->tasks->find(Uuid::fromString($id));
        if (null === $task) {
            throw new NotFoundHttpException('Task not found.');
        }

        /** @var array<string, mixed> $body */
        $body = \json_decode($request->getContent(), true) ?? [];
        $action = $body['action'] ?? null;
        if (!\in_array($action, ['complete', 'cancel', 'reassign'], true)) {
            throw new BadRequestHttpException('action must be complete, cancel or reassign.');
        }

        $userId = $this->requireUserId();
        if (!$this->mayResolve($task, $userId)) {
            throw new AccessDeniedHttpException(
                'Only the assignee or a workflow.approve_reject holder may act on this task.',
            );
        }

        try {
            match ($action) {
                'complete' => $task->complete($userId),
                'cancel' => $task->cancel($userId),
                'reassign' => $this->applyReassign($task, $body),
            };
        } catch (LogicException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        $this->tasks->save($task);

        return new JsonResponse(self::serialize($task));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyReassign(WorkflowTask $task, array $body): void
    {
        $userId = null;
        if (\is_string($body['assignee_user_id'] ?? null) && Uuid::isValid($body['assignee_user_id'])) {
            $userId = Uuid::fromString($body['assignee_user_id']);
        }
        $roleCode = \is_string($body['assignee_role_code'] ?? null) ? $body['assignee_role_code'] : null;

        $task->reassign($userId, $roleCode);
    }

    private function mayResolve(WorkflowTask $task, Uuid $userId): bool
    {
        if ($this->permissions->userHasPermission($userId, 'workflow.approve_reject')) {
            return true;
        }
        if (null !== $task->getAssigneeUserId() && $task->getAssigneeUserId()->equals($userId)) {
            return true;
        }
        $roleCode = $task->getAssigneeRoleCode();
        if (null !== $roleCode) {
            return \in_array($roleCode, $this->taskQueries->roleCodesOf($userId), true);
        }

        return false;
    }

    private function requireUserId(): Uuid
    {
        return $this->currentUser->userId()
            ?? throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(WorkflowTask $task): array
    {
        return [
            'id' => $task->getId()->toRfc4122(),
            'title' => $task->getTitle(),
            'type' => $task->getType()->value,
            'status' => $task->getStatus()->value,
            'object_id' => $task->getObjectId()?->toRfc4122(),
            'assignee_user_id' => $task->getAssigneeUserId()?->toRfc4122(),
            'assignee_role_code' => $task->getAssigneeRoleCode(),
            'due_date' => $task->getDueDate()?->format('Y-m-d'),
            'comment' => $task->getComment(),
            'context' => $task->getContext(),
            'created_by' => $task->getCreatedBy()?->toRfc4122(),
            'resolved_by' => $task->getResolvedBy()?->toRfc4122(),
            'resolved_at' => $task->getResolvedAt()?->format(DateTimeInterface::ATOM),
            'created_at' => $task->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
