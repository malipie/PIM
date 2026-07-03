<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Command\BulkEditValuesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-01 (#1961) — the headline write tool (UC2 "no price -> 100"):
 * materializes value edits as a pending_changes batch and returns its
 * id — the loop stops at awaiting_approval on seeing
 * `pending_change_batch_id` (ADR-0024 c: plan == diff; the commit is
 * P3-02, post-accept, through the real bulk-path).
 */
final readonly class BulkEditValuesTool implements AgentToolInterface
{
    public function __construct(
        private BulkEditValuesPort $bulkEdits,
        private PendingChangesPort $pendingChangesReader,
    ) {
    }

    public function name(): string
    {
        return 'bulk_edit_values';
    }

    public function description(): string
    {
        return 'Propose a bulk value edit: set attribute values on every object matching a filter DSL. Nothing is written to the catalog - '
            .'the proposal is materialized for human approval and you MUST report the returned counts to the user. '
            .'Ground the selector with aggregate_count first. If the user refers to the current view, omit filter_dsl.';
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
                    'description' => 'Selector: canonical filter DSL. Omit to use the active view filter. An empty selector targets EVERY object of the type - be sure that is what the user wants.',
                ],
                'changes' => [
                    'type' => 'object',
                    'description' => 'Map of attribute code to the new raw value, e.g. {"price": 100}.',
                ],
                'pending_change_batch_id' => [
                    'type' => 'string',
                    'description' => 'Append to an existing proposal batch (multi-step plans accumulate into one approval). Usually injected automatically.',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['overwrite', 'only_empty'],
                    'description' => 'overwrite replaces existing values; only_empty fills gaps only. Default: only_empty.',
                ],
            ],
            'required' => ['changes'],
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
        $changes = \is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [];
        /** @var array<string, mixed> $changes */
        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? $arguments['object_type_code'] : 'product';
        $modeRaw = $arguments['mode'] ?? null;
        $mode = \in_array($modeRaw, ['overwrite', 'only_empty'], true) ? $modeRaw : 'only_empty';

        $filterDsl = \is_array($arguments['filter_dsl'] ?? null) ? $arguments['filter_dsl'] : null;
        $viewFilter = $context->viewContext['filter_dsl'] ?? null;
        if (null === $filterDsl && \is_array($viewFilter)) {
            $filterDsl = $viewFilter;
        }
        /** @var array<string, mixed> $filterDslArray */
        $filterDslArray = $filterDsl ?? [];

        [$batchId, $batchError] = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null, PendingChangeType::Value);
        if (null === $batchId) {
            return ['error' => $batchError];
        }

        $proposal = $this->bulkEdits->materializeValueEdits(
            batchId: $batchId,
            userId: $context->userId,
            objectTypeCode: $objectTypeCode,
            filterDsl: $filterDslArray,
            changes: $changes,
            mode: $mode,
        );

        if (0 === $proposal->materializedChanges) {
            // Nothing materialized (all rejected or selector empty): no
            // batch id in the result, so the loop does NOT stop at
            // awaiting_approval - the model must explain and adjust.
            return [
                'materialized_changes' => 0,
                'affected_objects' => 0,
                'skipped_existing' => $proposal->skippedExisting,
                'rejected' => $proposal->rejected,
                'note' => 'Nothing was materialized - explain the rejections/selector to the user.',
            ];
        }

        return [
            'pending_change_batch_id' => $proposal->batchId->toRfc4122(),
            'affected_count' => $proposal->affectedObjects,
            'materialized_changes' => $proposal->materializedChanges,
            'skipped_existing' => $proposal->skippedExisting,
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
