<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
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
 * AGENT-P3-07 (#1967, SEC) — accountability in the DH Auditor: a
 * persisted run writes agent_run_started; approve->commit writes
 * agent_batch_committed carrying approver/model/tokens/cost; rollback
 * writes agent_batch_rolled_back — all in the SAME append-only
 * audit_logs the rest of the platform uses.
 */
final class AgentAuditTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function runStartLandsInTheAuditor(): void
    {
        [$em] = $this->fixture();
        $actor = Uuid::v7();
        $run = new AgentRun($actor, AgentRunSurface::Chat, 'set prices');
        $em->persist($run);
        $em->flush();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT user_id, new_value::text AS details FROM audit_logs WHERE action = :a AND resource_type = :t AND resource_id = :r',
            ['a' => 'agent_run_started', 't' => 'agent_run', 'r' => $run->getId()->toRfc4122()],
        );
        self::assertIsArray($row, 'agent_run_started must land in audit_logs');
        self::assertSame($actor->toRfc4122(), $row['user_id'], 'the actor is the run owner');
        self::assertIsString($row['details']);
        self::assertStringContainsString('set prices', $row['details']);
    }

    #[Test]
    public function commitAndRollbackCarryApproverModelAndCost(): void
    {
        [$em, $object] = $this->fixture();

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

        // materialize() cleared the EM — re-attach a managed tenant.
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        if ($detached instanceof Tenant) {
            $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $context->set($managed);
            }
        }

        $actor = Uuid::v7();
        $run = new AgentRun($actor, AgentRunSurface::Chat, 'set missing prices to 100');
        $run->setModel('claude-sonnet-4-6');
        $run->addUsage(1000, 500, '0.010500');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        $approver = Uuid::v7();
        $this->approval()->approve($run->getId(), $approver);

        $conn = $em->getConnection();
        $commitRow = $conn->fetchAssociative(
            'SELECT user_id, new_value::text AS details FROM audit_logs WHERE action = :a AND resource_id = :r',
            ['a' => 'agent_batch_committed', 'r' => $run->getId()->toRfc4122()],
        );
        self::assertIsArray($commitRow, 'agent_batch_committed must land in audit_logs');
        self::assertSame($actor->toRfc4122(), $commitRow['user_id'], 'actor stays the run owner; the approver travels in details');
        self::assertIsString($commitRow['details']);
        self::assertStringContainsString($approver->toRfc4122(), $commitRow['details']);
        self::assertStringContainsString('claude-sonnet-4-6', $commitRow['details']);
        self::assertStringContainsString('0.010500', $commitRow['details']);
        self::assertStringContainsString('tokens_input', $commitRow['details']);

        $this->approval()->rollback($run->getId());
        $rollbackRow = $conn->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :a AND resource_id = :r',
            ['a' => 'agent_batch_rolled_back', 'r' => $run->getId()->toRfc4122()],
        );
        self::assertSame(1, (int) (\is_scalar($rollbackRow) ? $rollbackRow : -1), 'rollback must land in audit_logs');
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
        $object = new CatalogObject($type, 'OBJ-1');
        $em->persist($object);
        $em->flush();

        return [$em, $object];
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
