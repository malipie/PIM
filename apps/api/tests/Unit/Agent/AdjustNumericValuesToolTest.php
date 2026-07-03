<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AdjustNumericValuesTool;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\ToolKind;
use App\Catalog\Contracts\Command\BulkEditValuesPort;
use App\Catalog\Contracts\Command\ValueEditProposal;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The agent arithmetic tool mirrors the manual increment_numeric bulk
 * action (MVP: the agent can do everything the user can in bulk). It
 * validates operator/operand up front, delegates the per-object
 * computation to the engine port, and reports the pending-change batch —
 * nothing is committed here.
 */
final class AdjustNumericValuesToolTest extends TestCase
{
    #[Test]
    public function metadataDeclaresAWriteToolGuardedByObjectWrite(): void
    {
        $tool = new AdjustNumericValuesTool($this->port(), $this->pendingChanges());

        self::assertSame('adjust_numeric_values', $tool->name());
        self::assertSame('object.write', $tool->requiredPermission());
        self::assertSame(ToolKind::Write, $tool->kind());
        $schema = $tool->parametersSchema();
        self::assertSame(['attr_code', 'operator', 'operand'], $schema['required']);
    }

    #[Test]
    public function delegatesTheComputedEditAndReportsTheBatch(): void
    {
        $port = $this->port(new ValueEditProposal(Uuid::v7(), affectedObjects: 3, materializedChanges: 3, skippedExisting: 1, rejected: []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $result = $tool->execute([
            'attr_code' => 'price',
            'operator' => '*',
            'operand' => 1.1,
            'filter_dsl' => ['field' => 'brand', 'op' => 'eq', 'value' => 'Acme'],
        ], $this->context());

        self::assertSame('price', $port->lastAttrCode);
        self::assertSame('*', $port->lastOperator);
        self::assertEqualsWithDelta(1.1, $port->lastOperand, 0.0001);
        self::assertSame(['field' => 'brand', 'op' => 'eq', 'value' => 'Acme'], $port->lastFilterDsl);
        self::assertSame('product', $port->lastObjectTypeCode, 'defaults to product');

        self::assertArrayHasKey('pending_change_batch_id', $result);
        self::assertSame(3, $result['affected_count']);
        self::assertSame(3, $result['materialized_changes']);
        self::assertSame(1, $result['skipped']);
    }

    #[Test]
    public function fallsBackToTheActiveViewFilterWhenNoneGiven(): void
    {
        $port = $this->port(new ValueEditProposal(Uuid::v7(), 1, 1, 0, []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $context = new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), [
            'filter_dsl' => ['field' => 'status', 'op' => 'eq', 'value' => 'draft'],
        ]);

        $tool->execute(['attr_code' => 'price', 'operator' => '+', 'operand' => 5], $context);

        self::assertSame(['field' => 'status', 'op' => 'eq', 'value' => 'draft'], $port->lastFilterDsl);
        self::assertNull($port->lastSelectedIds);
    }

    #[Test]
    public function usesTheContextSelectionOverTheViewFilter(): void
    {
        // #2153 — the operator has rows selected: default to the selection,
        // not the whole view.
        $port = $this->port(new ValueEditProposal(Uuid::v7(), 2, 2, 0, []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $context = new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), [
            'selected_ids' => ['id-1', 'id-2'],
            'filter_dsl' => ['field' => 'status', 'op' => 'eq', 'value' => 'draft'],
        ]);

        $tool->execute(['attr_code' => 'price', 'operator' => '*', 'operand' => 1.2], $context);

        self::assertSame(['id-1', 'id-2'], $port->lastSelectedIds, 'the selection is the selector');
        self::assertSame([], $port->lastFilterDsl, 'the view filter is not applied when a selection exists');
    }

    #[Test]
    public function explicitObjectIdsArgumentWinsOverTheSelection(): void
    {
        $port = $this->port(new ValueEditProposal(Uuid::v7(), 1, 1, 0, []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $context = new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), [
            'selected_ids' => ['id-1', 'id-2'],
        ]);

        $tool->execute([
            'attr_code' => 'price', 'operator' => '*', 'operand' => 2,
            'object_ids' => ['explicit-1'],
        ], $context);

        self::assertSame(['explicit-1'], $port->lastSelectedIds);
    }

    #[Test]
    public function explicitFilterDslArgumentSuppressesTheSelection(): void
    {
        // Passing a filter is a deliberate broader-scope choice by the model
        // (e.g. after the user confirmed "the whole list").
        $port = $this->port(new ValueEditProposal(Uuid::v7(), 5, 5, 0, []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $context = new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), [
            'selected_ids' => ['id-1', 'id-2'],
        ]);

        $tool->execute([
            'attr_code' => 'price', 'operator' => '*', 'operand' => 2,
            'filter_dsl' => ['field' => 'brand', 'op' => 'eq', 'value' => 'Acme'],
        ], $context);

        self::assertNull($port->lastSelectedIds);
        self::assertSame(['field' => 'brand', 'op' => 'eq', 'value' => 'Acme'], $port->lastFilterDsl);
    }

    #[Test]
    public function anEmptyContextSelectionIsIgnored(): void
    {
        $port = $this->port(new ValueEditProposal(Uuid::v7(), 1, 1, 0, []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $context = new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), [
            'selected_ids' => [],
            'filter_dsl' => ['field' => 'status', 'op' => 'eq', 'value' => 'draft'],
        ]);

        $tool->execute(['attr_code' => 'price', 'operator' => '+', 'operand' => 5], $context);

        self::assertNull($port->lastSelectedIds, 'empty selection is not a selection');
        self::assertSame(['field' => 'status', 'op' => 'eq', 'value' => 'draft'], $port->lastFilterDsl);
    }

    #[Test]
    public function zeroMaterializedReturnsAnExplainableNote(): void
    {
        $port = $this->port(new ValueEditProposal(Uuid::v7(), 0, 0, 4, []));
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $result = $tool->execute(['attr_code' => 'price', 'operator' => '*', 'operand' => 2], $this->context());

        self::assertSame(0, $result['materialized_changes']);
        self::assertArrayHasKey('note', $result);
        self::assertArrayNotHasKey('pending_change_batch_id', $result);
    }

    #[Test]
    public function rejectsAnUnsupportedOperatorBeforeTheEngine(): void
    {
        $port = $this->port();
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $result = $tool->execute(['attr_code' => 'price', 'operator' => '^', 'operand' => 2], $this->context());

        self::assertArrayHasKey('error', $result);
        self::assertFalse($port->called, 'a bad operator must not reach the engine');
    }

    #[Test]
    public function rejectsANonNumericOperand(): void
    {
        $port = $this->port();
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $result = $tool->execute(['attr_code' => 'price', 'operator' => '*', 'operand' => 'twice'], $this->context());

        self::assertArrayHasKey('error', $result);
        self::assertFalse($port->called);
    }

    #[Test]
    public function rejectsAMissingAttrCode(): void
    {
        $port = $this->port();
        $tool = new AdjustNumericValuesTool($port, $this->pendingChanges());

        $result = $tool->execute(['operator' => '*', 'operand' => 2], $this->context());

        self::assertArrayHasKey('error', $result);
        self::assertFalse($port->called);
    }

    private function context(): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), []);
    }

    private function port(?ValueEditProposal $proposal = null): RecordingBulkEditValuesPort
    {
        return new RecordingBulkEditValuesPort($proposal ?? new ValueEditProposal(Uuid::v7(), 1, 1, 0, []));
    }

    private function pendingChanges(): PendingChangesPort
    {
        return new class implements PendingChangesPort {
            public function materialize(Uuid $batchId, string $provenance, iterable $drafts): int
            {
                return 0;
            }

            public function listBatch(Uuid $batchId, int $limit = 100, int $offset = 0): array
            {
                return [];
            }

            public function iterateBatch(Uuid $batchId): iterable
            {
                return [];
            }

            public function countBatch(Uuid $batchId): int
            {
                return 0;
            }

            public function accept(Uuid $batchId): int
            {
                return 0;
            }

            public function reject(Uuid $batchId): int
            {
                return 0;
            }

            public function expire(Uuid $batchId): int
            {
                return 0;
            }

            public function annotate(Uuid $changeId, array $meta): void
            {
            }
        };
    }
}

/**
 * Records the arguments the tool forwards so the test can assert the
 * agent -> engine wiring without a database.
 */
final class RecordingBulkEditValuesPort implements BulkEditValuesPort
{
    public bool $called = false;
    public ?string $lastAttrCode = null;
    public ?string $lastOperator = null;
    public ?float $lastOperand = null;
    public ?string $lastObjectTypeCode = null;
    /** @var array<string, mixed>|null */
    public ?array $lastFilterDsl = null;
    /** @var list<mixed>|null */
    public ?array $lastSelectedIds = null;

    public function __construct(private readonly ValueEditProposal $proposal)
    {
    }

    public function materializeValueEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $changes,
        string $mode,
        ?array $selectedIds = null,
    ): ValueEditProposal {
        $this->called = true;
        $this->lastFilterDsl = $filterDsl;
        $this->lastSelectedIds = $selectedIds;

        return $this->proposal;
    }

    public function materializeArithmeticEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        string $attrCode,
        string $operator,
        float $operand,
        ?array $selectedIds = null,
    ): ValueEditProposal {
        $this->called = true;
        $this->lastAttrCode = $attrCode;
        $this->lastOperator = $operator;
        $this->lastOperand = $operand;
        $this->lastObjectTypeCode = $objectTypeCode;
        $this->lastFilterDsl = $filterDsl;
        $this->lastSelectedIds = $selectedIds;

        return $this->proposal;
    }
}
