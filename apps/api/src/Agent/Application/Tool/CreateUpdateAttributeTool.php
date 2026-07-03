<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

use const JSON_UNESCAPED_UNICODE;

/**
 * AGENT-P5-02 (#1971) — point modeling by conversation: "add attribute
 * weight (dimension) to group Wymiary". Materializes ONE schema diff
 * (the same cell shape as P5-01) into pending_changes; the approved
 * commit replays through the structural creators, which upsert by code
 * — an existing code becomes an UpdateAttributeCommand, a new one a
 * CreateAttributeCommand. Deleting schema is deliberately NOT in the
 * tool surface (MVP hook - risky blast radius).
 */
final readonly class CreateUpdateAttributeTool implements AgentToolInterface
{
    /** @see CreateAttributesFromSchemaTool::VALID_TYPES for the duplication rationale */
    private const array VALID_TYPES = [
        'text', 'number', 'select', 'multiselect', 'date', 'boolean',
        'asset', 'relation', 'price', 'metric', 'wysiwyg', 'datetime',
        'textarea', 'color', 'email', 'identifier',
    ];

    public function __construct(
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function name(): string
    {
        return 'create_update_attribute';
    }

    public function description(): string
    {
        return 'Propose creating or updating a SINGLE attribute (upsert by code: an existing code is updated, a new one created). '
            .'Nothing changes until a human approves. Valid types: '.implode(', ', self::VALID_TYPES).'. '
            .'type is required when creating; omit it when only updating labels/flags of an existing attribute. '
            .'Deleting attributes is not available - tell the user to do that in the modeling UI.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'type' => ['type' => 'string', 'description' => 'Attribute type; required for create, omit for update-only.'],
                'label' => ['type' => 'object', 'description' => 'Locale map, e.g. {"pl": "Waga", "en": "Weight"}.'],
                'groups' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Group codes to attach to.'],
                'is_localizable' => ['type' => 'boolean'],
                'is_scopable' => ['type' => 'boolean'],
                'is_required' => ['type' => 'boolean'],
                'is_filterable' => ['type' => 'boolean'],
                'options' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'For select/multiselect: [{"code": "silk", "label": {"pl": "Jedwab"}}].'],
            ],
            'required' => ['code', 'label'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'modeling.attributes.add_edit';
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

        $type = $arguments['type'] ?? null;
        if (null !== $type && (!\is_string($type) || !\in_array($type, self::VALID_TYPES, true))) {
            return ['error' => \sprintf('Unknown attribute type "%s" - ask the user which valid type fits.', \is_string($type) ? $type : '?')];
        }

        $cells = ['code' => $code];
        if (\is_string($type)) {
            $cells['type'] = $type;
        }
        foreach ($label as $locale => $text) {
            if (\is_string($locale) && \is_string($text)) {
                $cells['label.'.$locale] = $text;
            }
        }
        $groupCodes = [];
        $rawGroups = \is_array($arguments['groups'] ?? null) ? $arguments['groups'] : [];
        foreach ($rawGroups as $groupCode) {
            if (\is_string($groupCode) && '' !== $groupCode) {
                $groupCodes[] = $groupCode;
            }
        }
        if ([] !== $groupCodes) {
            $cells['groups'] = implode('|', $groupCodes);
        }
        foreach (['is_localizable', 'is_scopable', 'is_required', 'is_filterable'] as $flag) {
            if (\is_bool($arguments[$flag] ?? null)) {
                $cells[$flag] = $arguments[$flag] ? '1' : '0';
            }
        }
        if (\is_array($arguments['options'] ?? null) && [] !== $arguments['options']) {
            $encoded = json_encode($arguments['options'], JSON_UNESCAPED_UNICODE);
            if (\is_string($encoded)) {
                $cells['options'] = $encoded;
            }
        }

        [$batchId, $batchError] = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null, PendingChangeType::Schema);
        if (null === $batchId) {
            return ['error' => $batchError];
        }
        $this->pendingChanges->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Schema,
                targetObjectId: null,
                attributeCode: $code,
                before: null,
                after: ['schema_kind' => 'attribute', 'cells' => $cells],
            ),
        ]);

        return [
            'pending_change_batch_id' => $batchId->toRfc4122(),
            'affected_count' => 1,
            'code' => $code,
            'note' => 'Schema proposal awaits human approval in the inbox. Nothing is changed yet.',
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
        $rows = $this->pendingChanges->listBatch($batchId, 1);
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
