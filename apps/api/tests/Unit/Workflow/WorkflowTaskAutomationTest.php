<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\EditorialWorkflowProviderInterface;
use App\Workflow\Contracts\TaskAssignee;
use App\Workflow\Contracts\TransitionLogEntry;
use App\Workflow\Contracts\TransitionLogPort;
use App\Workflow\Contracts\WorkflowTaskType;
use App\Workflow\Domain\Entity\WorkflowTask;
use App\Workflow\Domain\Repository\WorkflowTaskRepositoryInterface;
use App\Workflow\Infrastructure\Messenger\WorkflowTaskAutomation;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * #2513 — the review-task recipient follows the definition's configured
 * approver (role OR a specific user), falling back to the built-in
 * reviewer role when the provider returns nothing (flag off / no
 * reviewer configured).
 */
final class WorkflowTaskAutomationTest extends TestCase
{
    #[Test]
    public function submitRoutesReviewTaskToTheConfiguredRole(): void
    {
        [$repo, $automation] = $this->makeAutomation(TaskAssignee::role('senior_editor'));

        $automation->onSubmittedForReview($this->submitEvent());

        $task = $repo->saved[0] ?? null;
        self::assertInstanceOf(WorkflowTask::class, $task);
        self::assertSame(WorkflowTaskType::Review, $task->getType());
        self::assertSame('senior_editor', $task->getAssigneeRoleCode());
        self::assertNull($task->getAssigneeUserId());
    }

    #[Test]
    public function submitRoutesReviewTaskToTheConfiguredUser(): void
    {
        $userId = '019f2bb4-1111-7000-8000-000000000001';
        [$repo, $automation] = $this->makeAutomation(TaskAssignee::user($userId));

        $automation->onSubmittedForReview($this->submitEvent());

        $task = $repo->saved[0] ?? null;
        self::assertInstanceOf(WorkflowTask::class, $task);
        self::assertNull($task->getAssigneeRoleCode());
        self::assertSame($userId, $task->getAssigneeUserId()?->toRfc4122());
    }

    #[Test]
    public function submitFallsBackToApproverWhenNoReviewerConfigured(): void
    {
        [$repo, $automation] = $this->makeAutomation(null);

        $automation->onSubmittedForReview($this->submitEvent());

        $task = $repo->saved[0] ?? null;
        self::assertInstanceOf(WorkflowTask::class, $task);
        self::assertSame('approver', $task->getAssigneeRoleCode());
        self::assertNull($task->getAssigneeUserId());
    }

    private function submitEvent(): ObjectSubmittedForReview
    {
        return new ObjectSubmittedForReview(
            objectId: Uuid::v7(),
            tenantId: Uuid::v7(),
            comment: 'Gotowe',
            objectTypeId: Uuid::v7(),
        );
    }

    /**
     * @return array{0: RecordingWorkflowTaskRepository, 1: WorkflowTaskAutomation}
     */
    private function makeAutomation(?TaskAssignee $reviewer): array
    {
        $repo = new RecordingWorkflowTaskRepository();

        $log = new class implements TransitionLogPort {
            public function pageForObject(Uuid $objectId, int $limit, ?Uuid $before = null): array
            {
                return [];
            }

            public function latestForObject(Uuid $objectId, string $transition): ?TransitionLogEntry
            {
                return null;
            }
        };

        $currentUser = new class implements CurrentUserProvider {
            public function userId(): ?Uuid
            {
                return null;
            }

            public function tenant(): ?Tenant
            {
                return null;
            }
        };

        $provider = new class($reviewer) implements EditorialWorkflowProviderInterface {
            public function __construct(private readonly ?TaskAssignee $reviewer)
            {
            }

            public function for(object $subject, ?string $objectTypeId = null): WorkflowInterface
            {
                throw new LogicException('not used');
            }

            public function reviewerFor(?string $objectTypeId): ?TaskAssignee
            {
                return $this->reviewer;
            }
        };

        return [$repo, new WorkflowTaskAutomation($repo, $log, $currentUser, $provider)];
    }
}

/**
 * Named (non-anonymous) so PHPStan keeps the concrete type through
 * {@see WorkflowTaskAutomationTest::makeAutomation()}'s return tuple.
 */
final class RecordingWorkflowTaskRepository implements WorkflowTaskRepositoryInterface
{
    /** @var list<WorkflowTask> */
    public array $saved = [];

    public function save(WorkflowTask $task): void
    {
        $this->saved[] = $task;
    }

    public function find(Uuid $id): ?WorkflowTask
    {
        return null;
    }

    public function page(
        int $limit,
        ?Uuid $before = null,
        ?\App\Workflow\Contracts\WorkflowTaskStatus $status = null,
        ?Uuid $objectId = null,
        ?Uuid $mineUserId = null,
        ?DateTimeImmutable $dueBefore = null,
        array $mineReviewerTaskTypes = [],
    ): array {
        return [];
    }

    public function openTasksFor(Uuid $objectId, WorkflowTaskType $type): array
    {
        return [];
    }
}
