<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Command\AssignCategoriesPort;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-05 (#1965) — mass categorisation through the approval gate:
 * materializes category assignments (add/remove/move) as a
 * pending_changes batch. Nothing touches the object_categories junction
 * before a human accepts; the commit (P3-02) goes through the existing
 * bulk category handlers, so the 24h rollback comes for free.
 */
final readonly class AssignCategoriesTool implements AgentToolInterface
{
    public function __construct(
        private AssignCategoriesPort $assignments,
    ) {
    }

    public function name(): string
    {
        return 'assign_categories';
    }

    public function description(): string
    {
        return 'Propose a bulk category assignment: add, remove or move every object matching a filter DSL to the given categories. '
            .'Nothing is written - the proposal is materialized for human approval and you MUST report the returned counts. '
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
                'filter_dsl' => [
                    'type' => 'object',
                    'description' => 'Selector: canonical filter DSL. Omit to use the active view filter. An empty selector targets EVERY object of the type.',
                ],
                'category_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'UUIDs of the target category objects.',
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

        $filterDsl = \is_array($arguments['filter_dsl'] ?? null) ? $arguments['filter_dsl'] : null;
        $viewFilter = $context->viewContext['filter_dsl'] ?? null;
        if (null === $filterDsl && \is_array($viewFilter)) {
            $filterDsl = $viewFilter;
        }
        /** @var array<string, mixed> $filterDslArray */
        $filterDslArray = $filterDsl ?? [];

        $proposal = $this->assignments->materializeCategoryAssignments(
            batchId: Uuid::v7(),
            userId: $context->userId,
            objectTypeCode: $objectTypeCode,
            filterDsl: $filterDslArray,
            categoryIds: $categoryIds,
            operation: $operation,
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
}
