<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P3-03 (#1963) — the clean "no" and the mid-flight stop: reject
 * transitions the batch to rejected and the run to rejected with ZERO
 * catalog writes; cancel interrupts planning/awaiting runs and expires
 * a materialized batch; both are idempotent; a decided run cannot be
 * approved afterwards.
 */
final class AgentRejectCancelTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function rejectExpiresProposalsAndLeavesTheCatalogUntouched(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object);
        $run = $this->awaitingRun($em, $batchId);

        $rejected = $this->approval()->reject($run->getId());

        self::assertSame(AgentRunStatus::Rejected, $rejected->getStatus());
        self::assertNotNull($rejected->getCompletedAt());

        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertCount(1, $rows);
        self::assertSame(PendingChangeStatus::Rejected, $rows[0]->status);
        self::assertNotNull($rows[0]->decidedAt);

        $values = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($values) ? $values : -1), 'reject must never touch the catalog');

        // Idempotent: a second reject returns as-is, no error.
        self::assertSame(AgentRunStatus::Rejected, $this->approval()->reject($run->getId())->getStatus());
    }

    #[Test]
    public function rejectedRunCannotBeApprovedAfterwards(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object);
        $run = $this->awaitingRun($em, $batchId);
        $this->approval()->reject($run->getId());

        $this->expectException(ApprovalConflictException::class);
        $this->approval()->approve($run->getId(), Uuid::v7());
    }

    #[Test]
    public function cancelExpiresTheBatchAndMarksTheRunCancelled(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object);
        $run = $this->awaitingRun($em, $batchId);

        $cancelled = $this->approval()->cancel($run->getId());

        self::assertSame(AgentRunStatus::Cancelled, $cancelled->getStatus());
        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertSame(PendingChangeStatus::Expired, $rows[0]->status);

        $values = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($values) ? $values : -1), 'cancel must never commit anything');

        // Idempotent.
        self::assertSame(AgentRunStatus::Cancelled, $this->approval()->cancel($run->getId())->getStatus());
    }

    #[Test]
    public function cancelInterruptsAPlanningRunWithoutABatch(): void
    {
        [$em] = $this->fixture();
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set prices');
        $em->persist($run);
        $em->flush();

        $cancelled = $this->approval()->cancel($run->getId());

        self::assertSame(AgentRunStatus::Cancelled, $cancelled->getStatus());
    }

    #[Test]
    public function doneRunCannotBeCancelledOrRejected(): void
    {
        [$em] = $this->fixture();
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set prices');
        $run->markAwaitingApproval(Uuid::v7(), 1);
        $run->markCommitting(Uuid::v7());
        $run->markDone(Uuid::v7());
        $em->persist($run);
        $em->flush();

        try {
            $this->approval()->cancel($run->getId());
            self::fail('a done run must not be cancellable');
        } catch (ApprovalConflictException) {
        }

        $this->expectException(ApprovalConflictException::class);
        $this->approval()->reject($run->getId());
    }

    /**
     * @return array{0: EntityManagerInterface, 1: CatalogObject}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $em->persist(new Attribute('price', ['en' => 'Price'], AttributeType::Number));

        $object = new CatalogObject($type, 'NOPRICE-1');
        $em->persist($object);
        $em->flush();

        return [$em, $object];
    }

    private function materializeBatch(EntityManagerInterface $em, CatalogObject $object): Uuid
    {
        $batchId = Uuid::v7();
        $this->pendingChanges()->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: $object->getId(),
                attributeCode: 'price',
                before: null,
                after: ['value' => 100],
            ),
        ]);

        return $batchId;
    }

    private function awaitingRun(EntityManagerInterface $em, Uuid $batchId): AgentRun
    {
        // materialize() clears the EM internally, detaching the Tenant in
        // TenantContext — re-attach a managed one before persisting the run.
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        if ($detached instanceof Tenant) {
            $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $context->set($managed);
            }
        }

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set missing prices to 100');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    private function approval(): AgentApprovalService
    {
        $service = self::getContainer()->get('test.agent.approval');
        \assert($service instanceof AgentApprovalService);

        return $service;
    }

    private function pendingChanges(): PendingChangesPort
    {
        return self::getContainer()->get(PendingChangesPort::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
