<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use Symfony\Component\Uid\Uuid;

use const JSON_UNESCAPED_UNICODE;

/**
 * AGENT-P5-01 (#1970) — UC1 headline schema tool ("IdoSell schema in
 * 5 minutes"): the model extracts groups + attributes from a pasted
 * schema and this tool materializes ONE schema diff per group and per
 * attribute into pending_changes (type=schema) — nothing touches
 * modeling before a human accepts. The commit replays the rows through
 * the real structural import (SchemaImportPort), so cell grammar,
 * upsert-by-code and option sync are the wizard's, not the agent's.
 *
 * kind=schema routes the run to the Opus-tier model (P0-06/P5-03) and
 * requires the modeling permission. An unknown attribute type is a
 * REJECTION, not a guess — the model must ask the user (AC: "dopytuje
 * o niejednoznaczne typy").
 */
final readonly class CreateAttributesFromSchemaTool implements AgentToolInterface
{
    /**
     * Deliberate duplication of Catalog's AttributeType values: Deptrac
     * bars Agent_Internals from Catalog_Internals, and the enum has no
     * Contracts home yet (deptrac.yaml TODO suggests Catalog\Contracts\Enum).
     * The commit path re-validates through the real creators anyway -
     * this list only lets the model fail fast and ask the user.
     */
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
        return 'create_attributes_from_schema';
    }

    public function description(): string
    {
        return 'Propose creating attribute groups and attributes from a schema the user supplied. Nothing is created - '
            .'the proposal is materialized for human approval; report the plan (N groups, M attributes, type mapping) and the rejections. '
            .'Valid attribute types: '.implode(', ', self::VALID_TYPES).'. '
            .'If a source type is ambiguous, ASK the user instead of guessing.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attribute_groups' => [
                    'type' => 'array',
                    'description' => 'Groups to create/update.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'label' => ['type' => 'object', 'description' => 'Locale map, e.g. {"pl": "Wymiary", "en": "Dimensions"}.'],
                            'description' => ['type' => 'object'],
                            'icon' => ['type' => 'string'],
                            'color' => ['type' => 'string'],
                        ],
                        'required' => ['code', 'label'],
                    ],
                ],
                'attributes' => [
                    'type' => 'array',
                    'description' => 'Attributes to create/update.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'description' => 'One of the valid attribute types.'],
                            'label' => ['type' => 'object', 'description' => 'Locale map.'],
                            'groups' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Group codes to attach to.'],
                            'is_localizable' => ['type' => 'boolean'],
                            'is_scopable' => ['type' => 'boolean'],
                            'is_required' => ['type' => 'boolean'],
                            'is_filterable' => ['type' => 'boolean'],
                            'options' => [
                                'type' => 'array',
                                'description' => 'For select/multiselect: [{"code": "silk", "label": {"pl": "Jedwab"}}].',
                                'items' => ['type' => 'object'],
                            ],
                        ],
                        'required' => ['code', 'type', 'label'],
                    ],
                ],
            ],
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
        $rejected = [];
        $drafts = [];

        $groups = \is_array($arguments['attribute_groups'] ?? null) ? $arguments['attribute_groups'] : [];
        $groupCount = 0;
        foreach ($groups as $group) {
            if (!\is_array($group)) {
                continue;
            }
            $code = $group['code'] ?? null;
            $label = $group['label'] ?? null;
            if (!\is_string($code) || '' === $code || !\is_array($label) || [] === $label) {
                $rejected[] = ['code' => \is_string($code) ? $code : '?', 'reason' => 'Group needs a code and a non-empty label map.'];
                continue;
            }

            $cells = ['code' => $code];
            foreach ($label as $locale => $text) {
                if (\is_string($locale) && \is_string($text)) {
                    $cells['label.'.$locale] = $text;
                }
            }
            $description = \is_array($group['description'] ?? null) ? $group['description'] : [];
            foreach ($description as $locale => $text) {
                if (\is_string($locale) && \is_string($text)) {
                    $cells['description.'.$locale] = $text;
                }
            }
            if (\is_string($group['icon'] ?? null)) {
                $cells['icon'] = $group['icon'];
            }
            if (\is_string($group['color'] ?? null)) {
                $cells['color'] = $group['color'];
            }

            $drafts[] = new PendingChangeDraft(
                changeType: PendingChangeType::Schema,
                targetObjectId: null,
                attributeCode: $code,
                before: null,
                after: ['schema_kind' => 'attribute_group', 'cells' => $cells],
            );
            ++$groupCount;
        }

        $attributes = \is_array($arguments['attributes'] ?? null) ? $arguments['attributes'] : [];
        $attributeCount = 0;
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }
            $code = $attribute['code'] ?? null;
            $type = $attribute['type'] ?? null;
            $label = $attribute['label'] ?? null;
            if (!\is_string($code) || '' === $code || !\is_array($label) || [] === $label) {
                $rejected[] = ['code' => \is_string($code) ? $code : '?', 'reason' => 'Attribute needs a code and a non-empty label map.'];
                continue;
            }
            if (!\is_string($type) || !\in_array($type, self::VALID_TYPES, true)) {
                $rejected[] = ['code' => $code, 'reason' => \sprintf('Unknown attribute type "%s" - ask the user which valid type fits.', \is_string($type) ? $type : '?')];
                continue;
            }

            $cells = ['code' => $code, 'type' => $type];
            foreach ($label as $locale => $text) {
                if (\is_string($locale) && \is_string($text)) {
                    $cells['label.'.$locale] = $text;
                }
            }
            $groupCodes = [];
            $rawGroups = \is_array($attribute['groups'] ?? null) ? $attribute['groups'] : [];
            foreach ($rawGroups as $groupCode) {
                if (\is_string($groupCode) && '' !== $groupCode) {
                    $groupCodes[] = $groupCode;
                }
            }
            if ([] !== $groupCodes) {
                $cells['groups'] = implode('|', $groupCodes);
            }
            foreach (['is_localizable', 'is_scopable', 'is_required', 'is_filterable'] as $flag) {
                if (\is_bool($attribute[$flag] ?? null)) {
                    $cells[$flag] = $attribute[$flag] ? '1' : '0';
                }
            }
            if (\is_array($attribute['options'] ?? null) && [] !== $attribute['options']) {
                $encoded = json_encode($attribute['options'], JSON_UNESCAPED_UNICODE);
                if (\is_string($encoded)) {
                    $cells['options'] = $encoded;
                }
            }

            $drafts[] = new PendingChangeDraft(
                changeType: PendingChangeType::Schema,
                targetObjectId: null,
                attributeCode: $code,
                before: null,
                after: ['schema_kind' => 'attribute', 'cells' => $cells],
            );
            ++$attributeCount;
        }

        if ([] === $drafts) {
            return [
                'materialized_groups' => 0,
                'materialized_attributes' => 0,
                'rejected' => $rejected,
                'note' => 'Nothing was materialized - resolve the rejections with the user first.',
            ];
        }

        $batchId = Uuid::v7();
        $this->pendingChanges->materialize($batchId, 'agent', $drafts);

        return [
            'pending_change_batch_id' => $batchId->toRfc4122(),
            'affected_count' => $groupCount + $attributeCount,
            'materialized_groups' => $groupCount,
            'materialized_attributes' => $attributeCount,
            'rejected' => $rejected,
            'note' => 'Schema proposal awaits human approval in the inbox. Nothing is created yet.',
        ];
    }
}
