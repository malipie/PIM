<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\CreateAttributesFromSchemaTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
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
 * AGENT-P5-01 (#1970) — UC1 "schema in 5 minutes" through the approval
 * gate: the tool materializes schema diffs (modeling untouched), an
 * unknown type is a rejection (the model must ask, never guess),
 * approve replays the rows through the REAL structural import (groups
 * before attributes, CQRS underneath), double approve is one commit,
 * and schema rollback refuses with a clear boundary until P5-04.
 */
final class AgentSchemaFlowTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function toolMaterializesSchemaDiffsWithoutTouchingModeling(): void
    {
        [$em] = $this->fixture();

        $result = $this->tool()->execute([
            'attribute_groups' => [
                ['code' => 'dimensions', 'label' => ['pl' => 'Wymiary', 'en' => 'Dimensions']],
            ],
            'attributes' => [
                ['code' => 'weight', 'type' => 'number', 'label' => ['pl' => 'Waga'], 'groups' => ['dimensions'], 'is_required' => true],
                ['code' => 'mystery', 'type' => 'hologram', 'label' => ['pl' => 'Zagadka']],
            ],
        ], $this->context());

        self::assertSame(1, $result['materialized_groups']);
        self::assertSame(1, $result['materialized_attributes']);
        self::assertIsArray($result['rejected']);
        self::assertCount(1, $result['rejected']);
        $rejection = $result['rejected'][0];
        self::assertIsArray($rejection);
        self::assertIsString($rejection['reason']);
        self::assertStringContainsString('hologram', $rejection['reason']);
        self::assertIsString($result['pending_change_batch_id']);

        // SEC: modeling untouched before approval.
        $conn = $em->getConnection();
        $groups = $conn->fetchOne('SELECT COUNT(*) FROM attribute_groups');
        self::assertSame(0, (int) (\is_scalar($groups) ? $groups : -1));
        $attributes = $conn->fetchOne("SELECT COUNT(*) FROM attributes WHERE code = 'weight'");
        self::assertSame(0, (int) (\is_scalar($attributes) ? $attributes : -1));

        $rows = $this->pendingChanges()->listBatch(Uuid::fromString($result['pending_change_batch_id']));
        self::assertCount(2, $rows);
        self::assertSame(PendingChangeType::Schema, $rows[0]->changeType);
        self::assertSame(PendingChangeStatus::Pending, $rows[0]->status);
    }

    #[Test]
    public function approveCreatesGroupsAndAttributesThroughTheStructuralImport(): void
    {
        [$em] = $this->fixture();

        $result = $this->tool()->execute([
            'attribute_groups' => [
                ['code' => 'dimensions', 'label' => ['pl' => 'Wymiary', 'en' => 'Dimensions'], 'icon' => 'Ruler'],
            ],
            'attributes' => [
                ['code' => 'weight', 'type' => 'number', 'label' => ['pl' => 'Waga', 'en' => 'Weight'], 'groups' => ['dimensions']],
                ['code' => 'material', 'type' => 'select', 'label' => ['pl' => 'Materiał'], 'options' => [
                    ['code' => 'silk', 'label' => ['pl' => 'Jedwab']],
                    ['code' => 'cotton', 'label' => ['pl' => 'Bawełna']],
                ]],
            ],
        ], $this->context());

        self::assertIsString($result['pending_change_batch_id']);
        $batchId = Uuid::fromString($result['pending_change_batch_id']);
        $run = $this->awaitingRun($em, $batchId);

        $approved = $this->approval()->approve($run->getId(), Uuid::v7());

        self::assertSame(AgentRunStatus::Done, $approved->getStatus());
        self::assertTrue($batchId->equals($approved->getBulkOperationId() ?? Uuid::v4()), 'the schema batch id is the operation handle');

        $conn = $em->getConnection();
        $group = $conn->fetchOne("SELECT COUNT(*) FROM attribute_groups WHERE code = 'dimensions'");
        self::assertSame(1, (int) (\is_scalar($group) ? $group : -1), 'approve must create the group');
        $weight = $conn->fetchOne("SELECT type FROM attributes WHERE code = 'weight'");
        self::assertSame('number', $weight);
        $options = $conn->fetchOne("SELECT COUNT(*) FROM attribute_options ao JOIN attributes a ON a.id = ao.attribute_id WHERE a.code = 'material'");
        self::assertSame(2, (int) (\is_scalar($options) ? $options : -1), 'select options must sync');

        // Idempotent: a second approve returns as-is, nothing duplicated.
        $again = $this->approval()->approve($run->getId(), Uuid::v7());
        self::assertTrue($approved->getBulkOperationId()?->equals($again->getBulkOperationId() ?? Uuid::v4()) ?? false);

        // Schema rollback boundary: refused until P5-04, never a 500.
        try {
            $this->approval()->rollback($run->getId());
            self::fail('schema rollback must refuse before P5-04');
        } catch (ApprovalConflictException $conflict) {
            self::assertStringContainsString('P5-04', $conflict->getMessage());
        }
    }

    /**
     * @return array{0: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$em];
    }

    private function awaitingRun(EntityManagerInterface $em, Uuid $batchId): AgentRun
    {
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        if ($detached instanceof Tenant) {
            $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $context->set($managed);
            }
        }

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'create attributes from the IdoSell schema');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    private function tool(): CreateAttributesFromSchemaTool
    {
        $tool = new CreateAttributesFromSchemaTool($this->pendingChanges());
        self::assertSame(ToolKind::Schema, $tool->kind());
        self::assertSame('modeling.attributes.add_edit', $tool->requiredPermission());

        return $tool;
    }

    private function context(): AgentToolContext
    {
        $tenant = self::getContainer()->get(TenantContext::class)->get();
        \assert($tenant instanceof Tenant);

        return new AgentToolContext(Uuid::v7(), $tenant, []);
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
