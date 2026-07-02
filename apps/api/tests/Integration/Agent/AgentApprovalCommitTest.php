<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\BulkSession;
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
 * AGENT-P3-02 (#1962, SEC failing-test-first) — the approval gate:
 * ONLY an accepted batch commits (rejected/expired transition nothing),
 * the commit goes through the real bulk-path (object_values rows with
 * provenance=agent + BulkSession + bulk_logs undo capture), double
 * approve is one commit, and the run lands on done with the
 * bulk_operation_id rollback handle.
 */
final class AgentApprovalCommitTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function approveCommitsThroughTheBulkPathWithProvenanceAgent(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object, before: null, after: ['value' => 100]);
        $run = $this->awaitingRun($em, $batchId);

        $approver = Uuid::v7();
        $approved = $this->approval()->approve($run->getId(), $approver);

        self::assertSame(AgentRunStatus::Done, $approved->getStatus());
        self::assertInstanceOf(Uuid::class, $approved->getBulkOperationId());
        self::assertTrue($approver->equals($approved->getApprovedBy() ?? Uuid::v4()));
        self::assertNotNull($approved->getApprovedAt());

        $conn = $em->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT ov.value::text AS value, ov.provenance, ov.provenance_meta::text AS meta FROM object_values ov JOIN objects co ON co.id = ov.object_id WHERE co.code = :c',
            ['c' => 'NOPRICE-1'],
        );
        self::assertIsArray($row, 'the accepted value must exist in object_values');
        self::assertSame('agent', $row['provenance']);
        self::assertIsString($row['value']);
        self::assertStringContainsString('100', $row['value']);
        self::assertIsString($row['meta']);
        self::assertStringContainsString($run->getId()->toRfc4122(), $row['meta'], 'provenance_meta carries agent_run_id (jsonb-schemas §5)');

        // Undo capture: BulkSession (source=cmd_k_agent) + info bulk_logs.
        $session = $em->find(BulkSession::class, $approved->getBulkOperationId());
        self::assertInstanceOf(BulkSession::class, $session);
        self::assertSame(BulkSession::SOURCE_CMD_K_AGENT, $session->getSource());
        self::assertSame('multi_attribute_edit', $session->getActionType());
        $logCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM bulk_logs WHERE bulk_session_id = :s AND level = :l',
            ['s' => $approved->getBulkOperationId()->toRfc4122(), 'l' => 'info'],
        );
        self::assertSame(1, (int) (\is_scalar($logCount) ? $logCount : -1));
    }

    #[Test]
    public function doubleApproveIsOneCommit(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object, before: null, after: ['value' => 100]);
        $run = $this->awaitingRun($em, $batchId);

        $first = $this->approval()->approve($run->getId(), Uuid::v7());
        $second = $this->approval()->approve($run->getId(), Uuid::v7());

        self::assertTrue($first->getBulkOperationId()?->equals($second->getBulkOperationId() ?? Uuid::v4()) ?? false);

        $conn = $em->getConnection();
        $values = $conn->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(1, (int) (\is_scalar($values) ? $values : -1), 'double approve must not duplicate values');
        $sessions = $conn->fetchOne('SELECT COUNT(*) FROM bulk_sessions');
        self::assertSame(1, (int) (\is_scalar($sessions) ? $sessions : -1), 'double approve must not open a second BulkSession');
    }

    #[Test]
    public function rejectedBatchDoesNotCommit(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object, before: null, after: ['value' => 100]);
        $this->pendingChanges()->reject($batchId);

        $result = $this->commitPort()->commitAcceptedBatch($batchId, Uuid::v7());

        self::assertNull($result->bulkSessionId);
        self::assertSame(0, $result->committedValues);
        $values = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($values) ? $values : -1), 'a rejected batch must never reach the catalog');
    }

    #[Test]
    public function expiredBatchDoesNotCommitAndApproveMarksError(): void
    {
        [$em, $object] = $this->fixture();
        $batchId = $this->materializeBatch($em, $object, before: null, after: ['value' => 100]);
        $run = $this->awaitingRun($em, $batchId);
        $this->pendingChanges()->expire($batchId);

        try {
            $this->approval()->approve($run->getId(), Uuid::v7());
            self::fail('approving an expired batch must conflict');
        } catch (ApprovalConflictException) {
        }

        $values = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($values) ? $values : -1));

        $fresh = $em->find(AgentRun::class, $run->getId());
        self::assertInstanceOf(AgentRun::class, $fresh);
        self::assertSame(AgentRunStatus::Error, $fresh->getStatus());
    }

    #[Test]
    public function approveRejectsRunsNotAwaitingApproval(): void
    {
        [$em] = $this->fixture();
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set prices');
        $em->persist($run);
        $em->flush();

        $this->expectException(ApprovalConflictException::class);
        $this->approval()->approve($run->getId(), Uuid::v7());
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

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     */
    private function materializeBatch(EntityManagerInterface $em, CatalogObject $object, ?array $before, array $after): Uuid
    {
        $batchId = Uuid::v7();
        $this->pendingChanges()->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: $object->getId(),
                attributeCode: 'price',
                before: $before,
                after: $after,
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

    private function commitPort(): PendingBatchCommitPort
    {
        return self::getContainer()->get(PendingBatchCommitPort::class);
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
