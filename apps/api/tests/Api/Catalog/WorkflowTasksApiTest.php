<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\WorkflowTaskType;
use App\Workflow\Domain\Entity\WorkflowTask;
use App\Workflow\Domain\Repository\WorkflowTaskRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Pakiet F = WFL-P4-01 (#2428) + WFL-P4-02 (#2429) — the task loop:
 * submit opens a role-assigned review task (idempotent), reject closes
 * it and opens a fix task for the submit author (with the reviewer
 * comment + notification), approve closes review tasks; unpublish
 * requests open/close request_unpublish tasks. Manual CRUD +
 * complete/cancel/reassign with assignee-or-approver authorization.
 */
final class WorkflowTasksApiTest extends CatalogApiTestCase
{
    #[Test]
    public function submitOpensReviewTaskIdempotentlyAndDecisionClosesIt(): void
    {
        $this->createUserWithRole('marketing-t@demo.localhost', 'marketing');
        $this->createUserWithRole('approver-t@demo.localhost', 'approver');
        $id = $this->seedProduct('WFL-T-LOOP');

        $marketing = $this->authenticatedClient('marketing-t@demo.localhost');
        $submit = $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review', [
            'json' => ['comment' => 'Do zadania'],
        ]);
        self::assertSame(200, $submit->getStatusCode());
        $this->drainAsyncTransport();

        // The approver sees the review task via mine (role membership).
        $approver = $this->authenticatedClient('approver-t@demo.localhost');
        $mine = $approver->request('GET', '/api/workflow/tasks?mine=1&status=open')->toArray();
        $items = $mine['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $task = $items[0];
        self::assertIsArray($task);
        self::assertSame('review', $task['type']);
        self::assertSame('approver', $task['assignee_role_code']);
        self::assertSame('Do zadania', $task['comment']);
        // #2518 — cards carry the resolved object summary + submitter name.
        self::assertSame('product', $task['object_kind']);
        self::assertSame('WFL-T-LOOP', $task['object_sku']);
        self::assertNotNull($task['created_by_name'], 'submitter display name resolved');

        // Re-submit does not duplicate the open task.
        $reject = $approver->request('POST', '/api/objects/'.$id.'/workflow/transitions/reject', [
            'json' => ['comment' => 'Popraw opis'],
        ]);
        self::assertSame(200, $reject->getStatusCode());
        $this->drainAsyncTransport();

        $resubmit = $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertSame(200, $resubmit->getStatusCode());
        $this->drainAsyncTransport();

        $open = $approver->request('GET', '/api/workflow/tasks?status=open&object_id='.$id)->toArray();
        $openItems = $open['items'];
        self::assertIsArray($openItems);
        $types = \array_column(\array_filter($openItems, 'is_array'), 'type');
        self::assertEqualsCanonicalizing(['review', 'fix'], $types, 'reject closed the first review task, opened fix; resubmit opened a fresh review');

        // Approve closes the open review task.
        $approve = $approver->request('POST', '/api/objects/'.$id.'/workflow/transitions/approve');
        self::assertSame(200, $approve->getStatusCode());
        $this->drainAsyncTransport();

        $after = $approver->request('GET', '/api/workflow/tasks?status=open&object_id='.$id)->toArray();
        $afterItems = $after['items'];
        self::assertIsArray($afterItems);
        $afterTypes = \array_column(\array_filter($afterItems, 'is_array'), 'type');
        self::assertSame(['fix'], $afterTypes, 'only the author fix task stays open');
    }

    #[Test]
    public function mineSurfacesReviewTaskForApprovePermissionHolderWithoutRoleMembership(): void
    {
        // #2495 — the review task is assigned to the `approver` role, but
        // the admin (super_admin, holds workflow.approve_reject) is not a
        // member of it. Before the fix, "mine" matched only direct
        // assignment or literal role membership, so the task inbox went
        // empty for the very people the notification fan-out pinged.
        $this->createUserWithRole('marketing-p@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-T-PERM');

        $marketing = $this->authenticatedClient('marketing-p@demo.localhost');
        $submit = $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertSame(200, $submit->getStatusCode());
        $this->drainAsyncTransport();

        // Admin holds the permission but not the approver role → sees it now.
        $admin = $this->authenticatedClient();
        $mine = $admin->request('GET', '/api/workflow/tasks?mine=1&status=open&object_id='.$id)->toArray();
        $items = $mine['items'];
        self::assertIsArray($items);
        $types = \array_column(\array_filter($items, 'is_array'), 'type');
        self::assertContains('review', $types, 'approve_reject holder sees the role-assigned review task in mine');

        // The submitter (marketing, no approve_reject, not approver) does not.
        $notMine = $marketing->request('GET', '/api/workflow/tasks?mine=1&status=open&object_id='.$id)->toArray();
        $notMineItems = $notMine['items'];
        self::assertIsArray($notMineItems);
        $notMineTypes = \array_column(\array_filter($notMineItems, 'is_array'), 'type');
        self::assertNotContains('review', $notMineTypes, 'a non-approver without the permission never sees review tasks in mine');
    }

    #[Test]
    public function mineSurfacesUserAssignedReviewTaskForApprovePermissionHolder(): void
    {
        // #2513 — a configurable approver can route the review task to a
        // specific user. An approve_reject holder still sees it in mine
        // (generalizes #2495 beyond role assignment; the role-code match
        // would have missed a user-assigned task).
        $this->createUserWithRole('mkt-ua@demo.localhost', 'marketing');
        $assignee = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('mkt-ua@demo.localhost');
        \assert($assignee instanceof User);
        $id = $this->seedProduct('WFL-T-UA');

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $task = new WorkflowTask(
            title: 'Review request',
            type: WorkflowTaskType::Review,
            objectId: Uuid::fromString($id),
            assigneeUserId: $assignee->getId(),
            createdBy: $assignee->getId(),
        );
        self::getContainer()->get(WorkflowTaskRepositoryInterface::class)->save($task);

        $admin = $this->authenticatedClient();
        $mine = $admin->request('GET', '/api/workflow/tasks?mine=1&status=open&object_id='.$id)->toArray();
        $items = $mine['items'];
        self::assertIsArray($items);
        $types = \array_column(\array_filter($items, 'is_array'), 'type');
        self::assertContains('review', $types, 'approve_reject holder sees a user-assigned review task in mine');
    }

    #[Test]
    public function rejectOpensFixTaskForTheAuthorWithNotification(): void
    {
        $this->createUserWithRole('marketing-f@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-T-FIX');

        $marketing = $this->authenticatedClient('marketing-f@demo.localhost');
        $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        $this->drainAsyncTransport();

        $admin = $this->authenticatedClient();
        $admin->request('POST', '/api/objects/'.$id.'/workflow/transitions/reject', [
            'json' => ['comment' => 'Brak EAN'],
        ]);
        $this->drainAsyncTransport();

        $mine = $marketing->request('GET', '/api/workflow/tasks?mine=1&status=open')->toArray();
        $items = $mine['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $task = $items[0];
        self::assertIsArray($task);
        self::assertSame('fix', $task['type']);
        self::assertSame('Brak EAN', $task['comment']);

        // The author may complete their own task; a foreign marketing
        // user may not (assignee-or-approver rule).
        $taskId = $task['id'];
        self::assertIsString($taskId);

        $this->createUserWithRole('marketing-f2@demo.localhost', 'marketing');
        $stranger = $this->authenticatedClient('marketing-f2@demo.localhost');
        $denied = $stranger->request('PATCH', '/api/workflow/tasks/'.$taskId, [
            'json' => ['action' => 'complete'],
        ]);
        self::assertSame(403, $denied->getStatusCode());

        $done = $marketing->request('PATCH', '/api/workflow/tasks/'.$taskId, [
            'json' => ['action' => 'complete'],
        ]);
        self::assertSame(200, $done->getStatusCode());

        $again = $marketing->request('PATCH', '/api/workflow/tasks/'.$taskId, [
            'json' => ['action' => 'complete'],
        ]);
        self::assertSame(409, $again->getStatusCode(), 'terminal tasks conflict on repeat actions');
    }

    #[Test]
    public function manualTaskCrudWithDueDateAndReassign(): void
    {
        $id = $this->seedProduct('WFL-T-MANUAL');

        $admin = $this->authenticatedClient();
        $created = $admin->request('POST', '/api/workflow/tasks', [
            'json' => [
                'title' => 'Uzupełnij zdjęcia',
                'object_id' => $id,
                'assignee_role_code' => 'marketing',
                'due_date' => '2026-08-01',
            ],
        ])->toArray();
        self::assertSame('custom', $created['type']);
        self::assertSame('2026-08-01', $created['due_date']);

        $taskId = $created['id'];
        self::assertIsString($taskId);

        $reassigned = $admin->request('PATCH', '/api/workflow/tasks/'.$taskId, [
            'json' => ['action' => 'reassign', 'assignee_role_code' => 'approver'],
        ])->toArray();
        self::assertSame('approver', $reassigned['assignee_role_code']);

        $due = $admin->request('GET', '/api/workflow/tasks?due_before=2026-08-02&status=open')->toArray();
        $dueItems = $due['items'];
        self::assertIsArray($dueItems);
        self::assertNotEmpty($dueItems);

        $cancelled = $admin->request('PATCH', '/api/workflow/tasks/'.$taskId, [
            'json' => ['action' => 'cancel'],
        ])->toArray();
        self::assertSame('cancelled', $cancelled['status']);
    }

    private function drainAsyncTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        if (!$transport instanceof InMemoryTransport) {
            return;
        }
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($transport->getSent() as $envelope) {
            $bus->dispatch($envelope->getMessage(), [new ReceivedStamp('async')]);
        }
        $transport->reset();
    }

    private function createUserWithRole(string $email, string $roleCode): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($role);
        $em->persist($user);
        $em->flush();
    }

    private function seedProduct(string $code): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($object);
        $em->flush();
        $em->clear();

        return $object->getId()->toRfc4122();
    }
}
