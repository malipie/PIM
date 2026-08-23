<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Command\SetStatusProposalPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

/** #2984 — proposes workflow transitions over the standard agent selector. */
final readonly class SetStatusTool implements AgentToolInterface
{
    use ResolvesSelectionScope;

    public function __construct(
        private SetStatusProposalPort $statuses,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function name(): string
    {
        return 'set_status';
    }

    public function description(): string
    {
        return 'Propose a workflow TRANSITION for selected catalog objects. Uses object_ids, filter_dsl, the current selection, or the active view filter. '
            .'Every object is checked with the initiating user workflow permissions and guards before it enters the diff; blocked objects and reasons are returned. '
            .'The transition is checked again after approval because object state may have changed. Nothing is written before approval.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_type_code' => ['type' => 'string', 'description' => 'Object type code. Default: product.'],
                'object_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Explicit object UUID selector.'],
                'filter_dsl' => ['type' => 'object', 'description' => 'Canonical filter DSL selector.'],
                'transition' => ['type' => 'string', 'description' => 'Workflow transition name, not a target status (e.g. submit_for_review, publish, approve, archive).'],
                'pending_change_batch_id' => ['type' => 'string', 'description' => 'Append only to a status proposal batch, or "new".'],
            ],
            'required' => ['transition'],
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
        $transition = \is_string($arguments['transition'] ?? null) ? trim($arguments['transition']) : '';
        if ('' === $transition) {
            return ['error' => 'transition is required.'];
        }
        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? trim($arguments['object_type_code']) : 'product';
        [$selectedIds, $filterDsl] = $this->resolveScope($arguments, $context);
        [$batchId, $error] = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null);
        if (null === $batchId) {
            return ['error' => $error];
        }

        $proposal = $this->statuses->materializeStatusTransition(
            $batchId,
            $context->userId,
            $objectTypeCode,
            $filterDsl,
            $transition,
            $selectedIds,
        );
        if (0 === $proposal->affectedObjects) {
            return [
                'affected_count' => 0,
                'blocked' => $proposal->blocked,
                'selector_rejected' => $proposal->selectorRejected,
                'note' => 'No transition was materialized. Explain the workflow blockers or selector mismatch.',
            ];
        }

        return [
            'pending_change_batch_id' => $proposal->batchId->toRfc4122(),
            'affected_count' => $proposal->affectedObjects,
            'transition' => $transition,
            'blocked' => $proposal->blocked,
            'selector_rejected' => $proposal->selectorRejected,
            'note' => 'Status proposal awaits human approval. Nothing is committed yet.',
        ];
    }

    /** @return array{0: ?Uuid, 1: ?string} */
    private function resolveBatch(mixed $requested): array
    {
        if (!\is_string($requested) || 'new' === $requested || !Uuid::isValid($requested)) {
            return [Uuid::v7(), null];
        }
        $batchId = Uuid::fromString($requested);
        $rows = $this->pendingChanges->listBatch($batchId, 1);
        if ([] !== $rows && PendingChangeType::Status !== $rows[0]->changeType) {
            return [null, \sprintf(
                'The current plan batch holds %s changes. Status transitions use a separate homogeneous batch; pass pending_change_batch_id: "new".',
                $rows[0]->changeType->value,
            )];
        }

        return [$batchId, null];
    }
}
