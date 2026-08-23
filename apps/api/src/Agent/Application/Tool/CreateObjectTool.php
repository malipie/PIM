<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Command\CreateObjectProposalPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

/** #2984 — creates an object proposal; the actual create is approval-gated. */
final readonly class CreateObjectTool implements AgentToolInterface
{
    public function __construct(
        private CreateObjectProposalPort $creations,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function name(): string
    {
        return 'create_object';
    }

    public function description(): string
    {
        return 'Propose creation of one catalog object with optional initial attributes and category UUIDs. Nothing is created before human approval. '
            .'Before calling, explicitly confirm the exact code/SKU and object type with the user; then pass confirmed=true. '
            .'Initial values use the same typed value normalization as bulk_edit_values. Use a separate proposal batch from other change families.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_type_code' => ['type' => 'string', 'description' => 'Exact object type code, e.g. product.'],
                'code' => ['type' => 'string', 'description' => 'Exact object code/SKU confirmed by the user.'],
                'attributes' => ['type' => 'object', 'description' => 'Initial values keyed by attribute code.'],
                'parent_id' => ['type' => 'string', 'description' => 'Optional parent object UUID.'],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional category object UUIDs; the first becomes primary.',
                ],
                'confirmed' => ['type' => 'boolean', 'description' => 'Must be true only after the user confirmed code and object type.'],
                'pending_change_batch_id' => ['type' => 'string', 'description' => 'Use "new" (recommended); object creation is a separate change family.'],
            ],
            'required' => ['object_type_code', 'code', 'confirmed'],
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
        if (true !== ($arguments['confirmed'] ?? false)) {
            return ['error' => 'Confirm the exact code/SKU and object_type_code with the user before materializing this proposal.'];
        }
        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? trim($arguments['object_type_code']) : '';
        $code = \is_string($arguments['code'] ?? null) ? trim($arguments['code']) : '';
        if ('' === $objectTypeCode || '' === $code) {
            return ['error' => 'object_type_code and code are required.'];
        }
        /** @var array<string, mixed> $attributes */
        $attributes = \is_array($arguments['attributes'] ?? null) ? $arguments['attributes'] : [];
        $parentId = \is_string($arguments['parent_id'] ?? null) ? $arguments['parent_id'] : null;
        $categories = [];
        if (\is_array($arguments['categories'] ?? null)) {
            foreach ($arguments['categories'] as $id) {
                if (\is_string($id) && '' !== $id) {
                    $categories[] = $id;
                }
            }
        }

        [$batchId, $error] = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null);
        if (null === $batchId) {
            return ['error' => $error];
        }
        $proposal = $this->creations->materializeObjectCreation(
            $batchId,
            $context->userId,
            $objectTypeCode,
            $code,
            $attributes,
            $parentId,
            $categories,
        );
        if (!$proposal->materialized) {
            return [
                'affected_count' => 0,
                'rejected' => $proposal->rejected,
                'note' => 'Nothing was materialized. Correct the reported fields or ask the user for another code.',
            ];
        }

        return [
            'pending_change_batch_id' => $proposal->batchId->toRfc4122(),
            'affected_count' => 1,
            'code' => $code,
            'object_type_code' => $objectTypeCode,
            'note' => 'Creation proposal awaits human approval. The object does not exist yet.',
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
        if ([] !== $rows && PendingChangeType::Object !== $rows[0]->changeType) {
            return [null, \sprintf(
                'The current plan batch holds %s changes. Object creation must use a separate homogeneous batch; pass pending_change_batch_id: "new".',
                $rows[0]->changeType->value,
            )];
        }

        return [$batchId, null];
    }
}
