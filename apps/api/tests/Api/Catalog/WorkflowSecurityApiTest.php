<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * WFL-P6-01 (#2434) — adversarial pass over the workflow surface. The
 * vectors that already had coverage stay where they are (raw status
 * PATCH: CatalogObjectStatusTransitionApiTest; bulk escalation:
 * BulkChangeStatusApiTest; task IDOR: WorkflowTasksApiTest;
 * notification IDOR: NotificationsApiTest). This file adds the missing
 * ones: cross-tenant isolation of every workflow surface, transition
 * on a foreign object, the double-approve race, and a pin on the MVP
 * self-approve decision (allowed — no four-eyes; known limitation
 * documented in docs/workflow.md, P6-03).
 */
final class WorkflowSecurityApiTest extends CatalogApiTestCase
{
    private const string TENANT_B_CODE = 'other';
    private const string ADMIN_B_EMAIL = 'admin@other.localhost';

    #[Test]
    public function crossTenantWorkflowSurfacesAreIsolated(): void
    {
        // Tenant B: an object with a submit applied (log row + review task
        // + approver notifications) and a workflow definition.
        $tenantB = $this->bootstrapTenantB();
        $clientB = $this->authenticatedClient(self::ADMIN_B_EMAIL);
        $idB = $this->createProductFor($clientB, $tenantB, 'WFL-SEC-B');

        $submit = $clientB->request(
            'POST',
            '/api/objects/'.$idB.'/workflow/transitions/submit_for_review',
            ['json' => ['comment' => 'tenant B secret comment']],
        );
        self::assertSame(200, $submit->getStatusCode());
        $this->drainAsyncTransport();

        $definition = $clientB->request('POST', '/api/workflow/definitions', [
            'json' => [
                'name' => 'Tenant B only',
                'places' => [['name' => 'draft'], ['name' => 'published']],
                'transitions' => [['name' => 'go', 'from' => 'draft', 'to' => 'published']],
            ],
        ]);
        self::assertSame(201, $definition->getStatusCode());

        // Tenant A admin sees NONE of it.
        $clientA = $this->authenticatedClient();

        $discovery = $clientA->request('GET', '/api/objects/'.$idB.'/workflow');
        self::assertSame(404, $discovery->getStatusCode(), 'discovery must not leak a foreign object');

        $log = $clientA->request('GET', '/api/objects/'.$idB.'/workflow/transitions');
        self::assertSame(404, $log->getStatusCode(), 'transition log must not leak a foreign object');

        $apply = $clientA->request('POST', '/api/objects/'.$idB.'/workflow/transitions/approve', [
            'json' => ['comment' => 'cross-tenant approve attempt'],
        ]);
        self::assertSame(404, $apply->getStatusCode(), 'foreign transition must 404, not 403 (no existence oracle)');

        $tasks = $clientA->request('GET', '/api/workflow/tasks?status=open')->toArray();
        $taskItems = $tasks['items'] ?? [];
        self::assertIsArray($taskItems);
        $taskObjects = \array_column($taskItems, 'object_id');
        self::assertNotContains($idB, $taskObjects, 'tenant B review task leaked into tenant A list');

        $notifications = $clientA->request('GET', '/api/notifications')->toArray();
        $payloads = \json_encode($notifications['items'] ?? [], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($idB, $payloads, 'tenant B notification leaked into tenant A');
        self::assertStringNotContainsString('tenant B secret comment', $payloads);

        $definitions = $clientA->request('GET', '/api/workflow/definitions')->toArray();
        $definitionItems = $definitions['items'] ?? [];
        self::assertIsArray($definitionItems);
        $names = \array_column($definitionItems, 'name');
        self::assertNotContains('Tenant B only', $names, 'tenant B definition leaked into tenant A');
    }

    #[Test]
    public function selfApproveIsAllowedInMvp(): void
    {
        // MVP decision (backlog WFL-P6-01): no four-eyes principle — the
        // submit author holding workflow.approve_reject MAY approve their
        // own submit. This pin makes the behaviour explicit; flipping it
        // in Faza 2 must consciously break this test.
        $client = $this->authenticatedClient();
        $id = $this->createProductFor($client, null, 'WFL-SEC-SELF');

        $submit = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertSame(200, $submit->getStatusCode());

        $approve = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/approve');
        self::assertSame(200, $approve->getStatusCode(), 'self-approve is allowed in MVP (no four-eyes)');
        self::assertSame('published', $approve->toArray()['current_place'] ?? null);
    }

    #[Test]
    public function secondApproveConflictsWithoutDuplicateSideEffects(): void
    {
        $client = $this->authenticatedClient();
        $id = $this->createProductFor($client, null, 'WFL-SEC-RACE');

        $submit = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertSame(200, $submit->getStatusCode());
        $this->drainAsyncTransport();

        $first = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/approve');
        self::assertSame(200, $first->getStatusCode());
        $this->drainAsyncTransport();

        // Second session replaying the same approve: the machine is no
        // longer in `review`, so the transition is not enabled -> 409.
        $second = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/approve');
        self::assertSame(409, $second->getStatusCode());
        $this->drainAsyncTransport();

        // Exactly one approve row in the log.
        $log = $client->request('GET', '/api/objects/'.$id.'/workflow/transitions?limit=50')->toArray();
        $logItems = $log['items'] ?? [];
        self::assertIsArray($logItems);
        $approves = \array_filter(
            $logItems,
            static fn (mixed $entry): bool => \is_array($entry) && 'approve' === ($entry['transition'] ?? null),
        );
        self::assertCount(1, $approves, 'replayed approve must not duplicate the transition log');

        // The review task closed once and stayed closed — no duplicates.
        $tasks = $client->request('GET', '/api/workflow/tasks?object_id='.$id)->toArray();
        $openItems = $tasks['items'] ?? [];
        self::assertIsArray($openItems);
        $reviewTasks = \array_values(\array_filter(
            $openItems,
            static fn (mixed $task): bool => \is_array($task) && 'review' === ($task['type'] ?? null),
        ));
        self::assertCount(1, $reviewTasks, 'exactly one review task for the object');
        $reviewTask = $reviewTasks[0];
        self::assertIsArray($reviewTask);
        self::assertSame('done', $reviewTask['status'] ?? null);
    }

    private function createProductFor(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        ?Tenant $tenant,
        string $code,
    ): string {
        $typeId = null === $tenant
            ? $this->objectTypeIdFor(ObjectKind::Product)
            : $this->objectTypeIdForTenant(ObjectKind::Product, $tenant);

        $response = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => \json_encode(['code' => $code, 'objectTypeId' => $typeId], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $id = $response->toArray()['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    private function objectTypeIdForTenant(ObjectKind $kind, Tenant $tenant): string
    {
        $type = self::getContainer()
            ->get(\App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind($kind, $tenant);
        \assert(null !== $type);

        return $type->getId()->toRfc4122();
    }

    private function bootstrapTenantB(): Tenant
    {
        $em = $this->em();

        $tenantB = new Tenant(self::TENANT_B_CODE, 'Other Tenant');
        $em->persist($tenantB);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenantB);
        $tenantOwnerB = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('tenant_owner', $tenantB);
        \assert(null !== $tenantOwnerB);

        $superAdmin = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenantB, self::ADMIN_B_EMAIL, '', ['ROLE_USER']);
        $adminB = new User($tenantB, self::ADMIN_B_EMAIL, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $adminB->addRole($superAdmin);
        $adminB->addRole($tenantOwnerB);
        $em->persist($adminB);
        $em->flush();

        self::getContainer()->get(BuiltInObjectTypeSeeder::class)->seed($tenantB);

        return $tenantB;
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
}
