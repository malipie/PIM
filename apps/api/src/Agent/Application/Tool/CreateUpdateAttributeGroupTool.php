<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P5-02 (#1971) — point group modeling by conversation:
 * materializes ONE schema diff (P5-01 cell shape) and the approved
 * commit upserts by code through the structural creator (Create /
 * UpdateAttributeGroupCommand underneath). No delete in the tool
 * surface (MVP hook).
 */
final readonly class CreateUpdateAttributeGroupTool implements AgentToolInterface
{
    public function __construct(
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function name(): string
    {
        return 'create_update_attribute_group';
    }

    public function description(): string
    {
        return 'Propose creating or updating a SINGLE attribute group (upsert by code). Nothing changes until a human approves. '
            .'Deleting groups is not available - tell the user to do that in the modeling UI.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'label' => ['type' => 'object', 'description' => 'Locale map, e.g. {"pl": "Wymiary", "en": "Dimensions"}.'],
                'description' => ['type' => 'object', 'description' => 'Locale map.'],
                'icon' => ['type' => 'string'],
                'color' => ['type' => 'string'],
            ],
            'required' => ['code', 'label'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'modeling.attribute_groups.add_edit';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Schema;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $code = $arguments['code'] ?? null;
        $label = $arguments['label'] ?? null;
        if (!\is_string($code) || '' === $code || !\is_array($label) || [] === $label) {
            return ['error' => 'code and a non-empty label map are required.'];
        }

        $cells = ['code' => $code];
        foreach ($label as $locale => $text) {
            if (\is_string($locale) && \is_string($text)) {
                $cells['label.'.$locale] = $text;
            }
        }
        $description = \is_array($arguments['description'] ?? null) ? $arguments['description'] : [];
        foreach ($description as $locale => $text) {
            if (\is_string($locale) && \is_string($text)) {
                $cells['description.'.$locale] = $text;
            }
        }
        if (\is_string($arguments['icon'] ?? null)) {
            $cells['icon'] = $arguments['icon'];
        }
        if (\is_string($arguments['color'] ?? null)) {
            $cells['color'] = $arguments['color'];
        }

        $batchId = Uuid::v7();
        $this->pendingChanges->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Schema,
                targetObjectId: null,
                attributeCode: $code,
                before: null,
                after: ['schema_kind' => 'attribute_group', 'cells' => $cells],
            ),
        ]);

        return [
            'pending_change_batch_id' => $batchId->toRfc4122(),
            'affected_count' => 1,
            'code' => $code,
            'note' => 'Schema proposal awaits human approval in the inbox. Nothing is changed yet.',
        ];
    }
}
