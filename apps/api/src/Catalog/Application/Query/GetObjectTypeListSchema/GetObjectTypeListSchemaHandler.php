<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetObjectTypeListSchema;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Identity\Contracts\Policy\AttributePermissionReader;

/**
 * ULV-03 (#984) — resolves the universal list schema for an ObjectType.
 *
 * Composition:
 *   1. System columns (always-on, fixed order): `code`, `status`,
 *      `completeness`, `updatedAt`. Drives the standard list header on
 *      every ObjectType regardless of attribute configuration.
 *   2. Attribute columns where the junction
 *      ({@see ObjectTypeAttribute}) carries `show_in_list = true`, sorted
 *      by `list_position` ascending then `attribute.code` as a stable
 *      tie-breaker.
 *
 * Filterable + searchable attribute lists are derived from
 * `attribute.isFilterable` / `attribute.isSearchable` flags (the same
 * flags the Meilisearch indexer reads), filtered to the attributes
 * actually attached to this ObjectType — the universal endpoint rejects
 * filter params whose attribute is not in this set.
 *
 * Field-level 3-state attribute permissions (`restricted`/`view`/`edit`)
 * land in ULV-04b — the schema returned here is the unfiltered ground
 * truth; ULV-04b adds a per-user mask that hides `restricted` columns.
 *
 * Returns null when the ObjectType id is unknown — controller maps that
 * to 404. Cross-tenant reads are blocked at the repository layer (the
 * `TenantFilter` Doctrine extension applies on `findById`).
 */
final readonly class GetObjectTypeListSchemaHandler
{
    public function __construct(
        private ObjectTypeRepositoryInterface $objectTypes,
        private ObjectTypeAttributeRepositoryInterface $junctions,
        private AttributePermissionReader $attributePermissions,
        private EffectiveAttributeGroupResolver $groupResolver,
    ) {
    }

    public function __invoke(GetObjectTypeListSchemaQuery $query): ?ObjectTypeListSchema
    {
        $objectType = $this->objectTypes->findById($query->objectTypeId);
        if (null === $objectType) {
            return null;
        }

        $junctions = $this->junctions->findByObjectType($objectType);
        // ULV-04b (#986) — drop junctions whose attribute is `restricted`
        // for the caller. The 3-state policy resolves to `Restricted`
        // when no per-role grant exists for the broad gate (PRD §3.5
        // Step 0) or when an explicit grant restricts it.
        $junctions = array_values(array_filter(
            $junctions,
            fn (ObjectTypeAttribute $j): bool => $this->attributePermissions->canViewAttribute(
                $j->getAttribute()->getId(),
            ),
        ));
        $listJunctions = array_values(array_filter(
            $junctions,
            static fn (ObjectTypeAttribute $j): bool => $j->isShownInList(),
        ));
        usort(
            $listJunctions,
            static function (ObjectTypeAttribute $a, ObjectTypeAttribute $b): int {
                $cmp = $a->getListPosition() <=> $b->getListPosition();

                return 0 !== $cmp ? $cmp : $a->getAttribute()->getCode() <=> $b->getAttribute()->getCode();
            },
        );

        // GRID-P3-01 (#2392) — full mode: every (RBAC-permitted) attached
        // attribute becomes a column; defaults (`show_in_list`) first in
        // their list order, the rest alphabetically by code.
        $columnJunctions = $listJunctions;
        $groupsByAttributeId = [];
        if ($query->full) {
            $rest = array_values(array_filter(
                $junctions,
                static fn (ObjectTypeAttribute $j): bool => !$j->isShownInList(),
            ));
            usort(
                $rest,
                static fn (ObjectTypeAttribute $a, ObjectTypeAttribute $b): int => $a->getAttribute()->getCode() <=> $b->getAttribute()->getCode(),
            );
            $columnJunctions = [...$listJunctions, ...$rest];
            $groupsByAttributeId = $this->mapAttributeGroups($objectType);
        }

        return new ObjectTypeListSchema(
            objectType: $this->projectObjectType($objectType),
            columns: $this->buildColumns($columnJunctions, $query->full, $groupsByAttributeId),
            filterableAttributes: $this->filterFiltering($junctions),
            searchableAttributes: $this->filterSearching($junctions),
        );
    }

    /**
     * @return array{id: string, code: string, kind: string, label: array<string, string>, is_categorizable: bool, has_variants: bool, has_multimedia: bool, expose_to_main_menu: bool}
     */
    private function projectObjectType(ObjectType $objectType): array
    {
        return [
            'id' => $objectType->getId()->toRfc4122(),
            'code' => $objectType->getCode(),
            'kind' => $objectType->getKind()->value,
            'label' => $objectType->getLabel(),
            'is_categorizable' => $objectType->isCategorizable(),
            'has_variants' => $objectType->hasVariants(),
            // UP-00 (#1017) — surface multimedia capability so the
            // UniversalDetailPage (UP-07) can gate the Multimedia tab.
            'has_multimedia' => $objectType->hasMultimedia(),
            'expose_to_main_menu' => $objectType->isExposedToMainMenu(),
        ];
    }

    /**
     * @param list<ObjectTypeAttribute>                                                                   $listJunctions
     * @param array<string, array{id: string, code: string, label: array<string, string>, position: int}> $groupsByAttributeId
     *
     * @return list<array{key: string, type: string, label: array<string, string>, position: int, sortable: bool, system: bool, default?: bool, group?: array{id: string, code: string, label: array<string, string>, position: int}|null}>
     */
    private function buildColumns(array $listJunctions, bool $full = false, array $groupsByAttributeId = []): array
    {
        $columns = [
            [
                'key' => 'code',
                'type' => 'system_identifier',
                'label' => ['pl' => 'Identyfikator', 'en' => 'Identifier'],
                'position' => 0,
                'sortable' => true,
                'system' => true,
            ],
            [
                'key' => 'status',
                'type' => 'system_status',
                'label' => ['pl' => 'Status', 'en' => 'Status'],
                'position' => 1,
                'sortable' => true,
                'system' => true,
            ],
            [
                'key' => 'completeness',
                'type' => 'system_completeness',
                'label' => ['pl' => 'Kompletność', 'en' => 'Completeness'],
                'position' => 2,
                'sortable' => true,
                'system' => true,
            ],
            [
                'key' => 'updatedAt',
                'type' => 'system_timestamp',
                'label' => ['pl' => 'Zmodyfikowano', 'en' => 'Modified'],
                'position' => 3,
                'sortable' => true,
                'system' => true,
            ],
        ];

        $position = \count($columns);
        foreach ($listJunctions as $junction) {
            $attribute = $junction->getAttribute();
            $column = [
                'key' => $attribute->getCode(),
                'type' => $attribute->getType()->value,
                'label' => $attribute->getLabel(),
                'position' => $position++,
                // ADR-0028 — sortable = simple, non-localizable,
                // non-scopable types; enforced independently by the
                // list endpoint in GRID-P5-02.
                'sortable' => $this->isSortableAttribute($attribute),
                'system' => false,
            ];
            if ($full) {
                $column['default'] = $junction->isShownInList();
                $column['group'] = $groupsByAttributeId[$attribute->getId()->toRfc4122()] ?? null;
            }
            $columns[] = $column;
        }

        return $columns;
    }

    private const array SORTABLE_TYPES = [
        AttributeType::Text,
        AttributeType::Textarea,
        AttributeType::Identifier,
        AttributeType::Number,
        AttributeType::Metric,
        AttributeType::Date,
        AttributeType::Datetime,
        AttributeType::Boolean,
        AttributeType::Select,
        AttributeType::Price,
        AttributeType::Email,
        AttributeType::Color,
    ];

    private function isSortableAttribute(Attribute $attribute): bool
    {
        return \in_array($attribute->getType(), self::SORTABLE_TYPES, true)
            && !$attribute->isLocalizable()
            && !$attribute->isScopable();
    }

    /**
     * attributeId → projekcja grupy. Groups resolve through the same
     * service the form surfaces use; an attribute living in several
     * groups reports the first (lowest-position) one.
     *
     * @return array<string, array{id: string, code: string, label: array<string, string>, position: int}>
     */
    private function mapAttributeGroups(ObjectType $objectType): array
    {
        $groups = $this->groupResolver->resolveForCategoryList($objectType, []);
        $byGroup = $this->groupResolver->loadGroupAttributes($groups);

        $map = [];
        foreach ($groups as $group) {
            foreach ($byGroup[$group->getId()->toRfc4122()] ?? [] as $groupJunction) {
                $attributeId = $groupJunction->getAttribute()->getId()->toRfc4122();
                if (!isset($map[$attributeId])) {
                    $map[$attributeId] = $this->projectGroup($group);
                }
            }
        }

        return $map;
    }

    /**
     * @return array{id: string, code: string, label: array<string, string>, position: int}
     */
    private function projectGroup(AttributeGroup $group): array
    {
        return [
            'id' => $group->getId()->toRfc4122(),
            'code' => $group->getCode(),
            'label' => $group->getLabel(),
            'position' => $group->getPosition(),
        ];
    }

    /**
     * @param list<ObjectTypeAttribute> $junctions
     *
     * @return list<string>
     */
    private function filterFiltering(array $junctions): array
    {
        $codes = [];
        foreach ($junctions as $junction) {
            $attribute = $junction->getAttribute();
            if ($attribute->isFilterable()) {
                $codes[] = $attribute->getCode();
            }
        }
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * @param list<ObjectTypeAttribute> $junctions
     *
     * @return list<string>
     */
    private function filterSearching(array $junctions): array
    {
        $codes = [];
        foreach ($junctions as $junction) {
            $attribute = $junction->getAttribute();
            if ($this->isSearchable($attribute)) {
                $codes[] = $attribute->getCode();
            }
        }
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * MVP heuristic — there is no `is_searchable` attribute flag yet; we
     * treat text-type filterable attributes as searchable since those are
     * the ones Meilisearch's full-text index actually scores well. A
     * dedicated flag (and per-attribute weight) can land later without
     * breaking the schema contract.
     */
    private function isSearchable(Attribute $attribute): bool
    {
        if (!$attribute->isFilterable()) {
            return false;
        }

        return match ($attribute->getType()) {
            // #1177 — textarea is free-form text content scored well by
            // Meilisearch full-text; color/email are exact-match, not searchable.
            AttributeType::Text, AttributeType::Wysiwyg, AttributeType::Textarea => true,
            default => false,
        };
    }
}
