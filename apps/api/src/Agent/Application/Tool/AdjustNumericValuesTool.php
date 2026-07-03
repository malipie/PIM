<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Command\BulkEditValuesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

/**
 * Arithmetic counterpart of bulk_edit_values (the manual
 * `increment_numeric` bulk action, exposed to the agent so it can do
 * everything the user can). Applies an operator (+ - * / %) to a numeric
 * attribute across a selector — "raise price by 10%" -> operator '*',
 * operand 1.1. Computes the after-value per object from its current
 * value, so it does NOT need to know the current price up front.
 *
 * Nothing is written: the result is a pending_changes batch for human
 * approval (ADR-0024 c), committed post-accept through the real
 * bulk-path. Non-numeric current values and division-by-zero are
 * skipped, never errored.
 */
final readonly class AdjustNumericValuesTool implements AgentToolInterface
{
    use ResolvesSelectionScope;

    public function __construct(
        private BulkEditValuesPort $bulkEdits,
        private PendingChangesPort $pendingChangesReader,
    ) {
    }

    public function name(): string
    {
        return 'adjust_numeric_values';
    }

    public function description(): string
    {
        return 'Propose an ARITHMETIC bulk edit of a numeric attribute (e.g. price) across every object matching a filter DSL: '
            .'multiply/add/subtract/divide/modulo by an operand, computed per object from its CURRENT value. '
            .'Use this instead of bulk_edit_values when the user asks to change a value RELATIVE to itself ("raise price by 10%%" -> operator "*", operand 1.1; "double the price" -> "*", 2). '
            .'Nothing is written - the proposal is materialized for human approval and you MUST report the returned counts. '
            .'Objects whose current value is empty/non-numeric are skipped. Ground the selector with aggregate_count first. '
            .'Selector precedence: explicit object_ids, else filter_dsl, else the operator\'s current SELECTION (selected_ids in the view context), else the active view. '
            .'When the view context has a non-empty selected_ids and the user did not clearly ask for the whole list, act on the SELECTION (omit object_ids and filter_dsl).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attr_code' => [
                    'type' => 'string',
                    'description' => 'Numeric attribute code to adjust, e.g. price_gross.',
                ],
                'operator' => [
                    'type' => 'string',
                    'enum' => ['+', '-', '*', '/', '%'],
                    'description' => 'Arithmetic operator applied to the current value.',
                ],
                'operand' => [
                    'type' => 'number',
                    'description' => 'The operand, e.g. 1.1 for +10%, 2 for double, 0.9 for -10%.',
                ],
                'object_type_code' => [
                    'type' => 'string',
                    'description' => 'ObjectType code (e.g. product). Default: product.',
                ],
                'object_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Selector (highest precedence): explicit object UUIDs to adjust, e.g. the operator\'s current selection. Omit to fall back to filter_dsl / the view selection.',
                ],
                'filter_dsl' => [
                    'type' => 'object',
                    'description' => 'Selector: canonical filter DSL. Omit to use the current selection (selected_ids) or the active view filter. An empty selector targets EVERY object of the type - be sure that is what the user wants.',
                ],
                'pending_change_batch_id' => [
                    'type' => 'string',
                    'description' => 'Append to an existing proposal batch (multi-step plans accumulate into one approval). Usually injected automatically.',
                ],
            ],
            'required' => ['attr_code', 'operator', 'operand'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'object.write';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $attrCode = \is_string($arguments['attr_code'] ?? null) ? $arguments['attr_code'] : '';
        if ('' === $attrCode) {
            return ['error' => 'attr_code is required.'];
        }
        $operatorRaw = $arguments['operator'] ?? null;
        $operator = \in_array($operatorRaw, ['+', '-', '*', '/', '%'], true) ? $operatorRaw : null;
        if (null === $operator) {
            return ['error' => 'operator must be one of + - * / %.'];
        }
        if (!is_numeric($arguments['operand'] ?? null)) {
            return ['error' => 'operand must be a number.'];
        }
        $operand = (float) $arguments['operand'];

        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? $arguments['object_type_code'] : 'product';

        [$selectedIds, $filterDslArray] = $this->resolveScope($arguments, $context);

        [$batchId, $batchError] = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null, PendingChangeType::Value);
        if (null === $batchId) {
            return ['error' => $batchError];
        }

        $proposal = $this->bulkEdits->materializeArithmeticEdits(
            batchId: $batchId,
            userId: $context->userId,
            objectTypeCode: $objectTypeCode,
            filterDsl: $filterDslArray,
            attrCode: $attrCode,
            operator: $operator,
            operand: $operand,
            selectedIds: $selectedIds,
        );

        if (0 === $proposal->materializedChanges) {
            return [
                'materialized_changes' => 0,
                'affected_objects' => 0,
                'skipped' => $proposal->skippedExisting,
                'rejected' => $proposal->rejected,
                'note' => 'Nothing was materialized - the selector may be empty or all current values are non-numeric. Explain to the user.',
            ];
        }

        return [
            'pending_change_batch_id' => $proposal->batchId->toRfc4122(),
            'affected_count' => $proposal->affectedObjects,
            'materialized_changes' => $proposal->materializedChanges,
            'skipped' => $proposal->skippedExisting,
            'rejected' => $proposal->rejected,
            'note' => 'Proposal awaits human approval in the inbox. Nothing is committed yet.',
        ];
    }

    /**
     * @return array{0: ?Uuid, 1: ?string} [batchId, error]
     */
    private function resolveBatch(mixed $requested, PendingChangeType $family): array
    {
        if (!\is_string($requested) || 'new' === $requested || !Uuid::isValid($requested)) {
            return [Uuid::v7(), null];
        }
        $batchId = Uuid::fromString($requested);
        $rows = $this->pendingChangesReader->listBatch($batchId, 1);
        if ([] !== $rows && $rows[0]->changeType !== $family) {
            return [null, \sprintf(
                'The current plan batch holds %s changes - this tool materializes %s. Finish the current plan for approval first, or pass pending_change_batch_id: "new" to open a separate proposal.',
                $rows[0]->changeType->value,
                $family->value,
            )];
        }

        return [$batchId, null];
    }
}
