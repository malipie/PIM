<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Command\AssignCategoriesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-05 (#1965) — mass categorisation through the approval gate:
 * materializes category assignments (add/remove/move) as a
 * pending_changes batch. Nothing touches the object_categories junction
 * before a human accepts; the commit (P3-02) goes through the existing
 * bulk category handlers, so the 24h rollback comes for free.
 */
final readonly class AssignCategoriesTool implements AgentToolInterface, ProvidesQuickActionInterface
{
    use ResolvesSelectionScope;

    public function __construct(
        private AssignCategoriesPort $assignments,
        private PendingChangesPort $pendingChangesReader,
    ) {
    }

    public function name(): string
    {
        return 'assign_categories';
    }

    public function description(): string
    {
        return 'Propose a bulk category assignment: add, remove or move a set of objects to the given categories. '
            .'Nothing is written - the proposal is materialized for human approval and you MUST report the returned counts. '
            .'Selector precedence: explicit object_ids, else filter_dsl, else the operator\'s current SELECTION (selected_ids in the view context), else the active view filter. '
            .'When the view context has a non-empty selected_ids and the user did not clearly ask for the whole list, act on the SELECTION (omit object_ids and filter_dsl). '
            .'Ground the selector with aggregate_count first. Category ids must be UUIDs of category objects (search them first if you only know the name).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_type_code' => [
                    'type' => 'string',
                    'description' => 'ObjectType code (e.g. product). Default: product.',
                ],
                'object_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Selector (highest precedence): explicit object UUIDs, e.g. the operator\'s current selection. Omit to fall back to filter_dsl / the view selection.',
                ],
                'filter_dsl' => [
                    'type' => 'object',
                    'description' => 'Selector: canonical filter DSL. Omit to use the current selection (selected_ids) or the active view filter. An empty selector targets EVERY object of the type.',
                ],
                'category_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'UUIDs of the target category objects.',
                ],
                'pending_change_batch_id' => [
                    'type' => 'string',
                    'description' => 'Append to an existing proposal batch (multi-step plans accumulate into one approval). Usually injected automatically.',
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['add', 'remove', 'move'],
                    'description' => 'add keeps existing assignments; remove detaches the given categories; move replaces all assignments with the given ones. Default: add.',
                ],
            ],
            'required' => ['category_ids'],
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

    public function quickAction(): AgentQuickAction
    {
        return new AgentQuickAction(
            id: $this->name(),
            label: ['pl' => 'Bulk update kategorii', 'en' => 'Bulk category update'],
            prompt: [
                'pl' => 'Przypisz produkty [filtr lub zaznaczenie] do kategorii [kategoria]',
                'en' => 'Assign products [filter or selection] to category [category]',
            ],
            priority: 20,
        );
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $rawIds = \is_array($arguments['category_ids'] ?? null) ? $arguments['category_ids'] : [];
        $categoryIds = [];
        foreach ($rawIds as $id) {
            if (\is_string($id) && '' !== $id) {
                $categoryIds[] = $id;
            }
        }

        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? $arguments['object_type_code'] : 'product';
        $operationRaw = $arguments['operation'] ?? null;
        $operation = \in_array($operationRaw, ['add', 'remove', 'move'], true) ? $operationRaw : 'add';

        [$selectedIds, $filterDslArray] = $this->resolveScope($arguments, $context);

        [$batchId, $batchError] = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null, PendingChangeType::Category);
        if (null === $batchId) {
            return ['error' => $batchError];
        }

        $proposal = $this->assignments->materializeCategoryAssignments(
            batchId: $batchId,
            userId: $context->userId,
            objectTypeCode: $objectTypeCode,
            filterDsl: $filterDslArray,
            categoryIds: $categoryIds,
            operation: $operation,
            selectedIds: $selectedIds,
        );

        if (0 === $proposal->affectedObjects) {
            return [
                'affected_objects' => 0,
                'rejected' => $proposal->rejected,
                'note' => 'Nothing was materialized - explain the rejections/selector to the user.',
            ];
        }

        return [
            'pending_change_batch_id' => $proposal->batchId->toRfc4122(),
            'affected_count' => $proposal->affectedObjects,
            'operation' => $operation,
            'rejected' => $proposal->rejected,
            'note' => 'Proposal awaits human approval in the inbox. Nothing is committed yet.',
        ];
    }

    /**
     * AGENT-P8-03 (#1985) — resolve the target batch for a multi-step
     * plan: 'new' forces a fresh proposal, a valid UUID appends ONLY if
     * the existing batch holds the same change family (mixing families
     * in one batch would poison the commit path).
     *
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
