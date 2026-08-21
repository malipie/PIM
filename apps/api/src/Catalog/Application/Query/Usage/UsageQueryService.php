<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Usage;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * UI-08.7 (#262) — `where-used` count + reference summaries for
 * Attribute / AttributeGroup / ObjectType, surfaced as the
 * `<WhereUsedList>` widget in `#UI-08.11` and the delete-protection
 * confirm modal in `#UI-08.13`.
 *
 * Strategy:
 *   - DBAL only (cross-BC counts hit `api_profiles` JSONB; staying in
 *     SQL avoids reaching into ApiConfigurator domain code, keeping
 *     Deptrac green).
 *   - Cached in `pim.modeling_cache` with TTL=60s (per epik plan §6.2 —
 *     usage data ages in seconds, not minutes; cache mainly absorbs
 *     burst reads from the admin panel switching tabs).
 *   - Invalidated by tags `pim_usage` (global) and
 *     `pim_usage.<resource>.<id>` (per-row). The shared invalidator in
 *     `ObjectFormSchemaCacheInvalidator` already nukes this on schema
 *     changes; here we just register the same tag set.
 */
final readonly class UsageQueryService
{
    public const string CACHE_TAG = 'pim_usage';
    public const int CACHE_TTL_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private TagAwareCacheInterface $modelingCache,
    ) {
    }

    /**
     * @return array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int
     * }
     */
    public function forAttribute(Attribute $attribute): array
    {
        $key = \sprintf('pim_usage_attribute_%s', $attribute->getId()->toRfc4122());

        return $this->modelingCache->get($key, function (ItemInterface $item) use ($attribute): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $item->tag([self::CACHE_TAG, self::CACHE_TAG.'.attribute.'.$attribute->getId()->toRfc4122()]);

            return $this->loadAttributeUsage($attribute);
        });
    }

    /**
     * Uncached variant for destructive write guards. A delete decision must
     * never depend on a read-model entry that may remain stale until its TTL.
     *
     * @return array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int
     * }
     */
    public function forAttributeFresh(Attribute $attribute): array
    {
        return $this->loadAttributeUsage($attribute);
    }

    /**
     * @return array{
     *     directlyAttachedTo: array{
     *         objectTypes: list<array{id: string, code: string, kind: string}>,
     *         categories: list<array{id: string, path: string|null, target_kind: string|null}>
     *     },
     *     attributeCount: int,
     *     affectedInstanceCount: int
     * }
     */
    public function forAttributeGroup(AttributeGroup $group): array
    {
        $key = \sprintf('pim_usage_attribute_group_%s', $group->getId()->toRfc4122());

        return $this->modelingCache->get($key, function (ItemInterface $item) use ($group): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $item->tag([self::CACHE_TAG, self::CACHE_TAG.'.attribute_group.'.$group->getId()->toRfc4122()]);

            return $this->loadAttributeGroupUsage($group);
        });
    }

    /**
     * @return array{
     *     instanceCount: int,
     *     attributesAttachedCount: int,
     *     attributeGroupsAttachedCount: int,
     *     referencedByApiProfileCount: int,
     *     referencedByCategoryAttachmentCount: int
     * }
     */
    public function forObjectType(ObjectType $type): array
    {
        $key = \sprintf('pim_usage_object_type_%s', $type->getId()->toRfc4122());

        return $this->modelingCache->get($key, function (ItemInterface $item) use ($type): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $item->tag([self::CACHE_TAG, self::CACHE_TAG.'.object_type.'.$type->getId()->toRfc4122()]);

            return $this->loadObjectTypeUsage($type);
        });
    }

    /**
     * @return array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int
     * }
     */
    private function loadAttributeUsage(Attribute $attribute): array
    {
        $attributeId = $attribute->getId()->toRfc4122();

        $groups = $this->connection->fetchAllAssociative(
            'SELECT g.id, g.code, g.label FROM attribute_groups g'
            .' JOIN attribute_group_attributes j ON j.attribute_group_id = g.id'
            .' WHERE j.attribute_id = ?'
            .' ORDER BY g.code',
            [$attributeId],
        );

        $objectTypes = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ot.id, ot.code, ot.kind FROM object_types ot'
            .' JOIN object_type_attributes ota ON ota.object_type_id = ot.id'
            .' WHERE ota.attribute_id = ?'
            .' ORDER BY ot.code',
            [$attributeId],
        );

        $categories = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.path::text AS path FROM objects c'
            ." WHERE c.kind = 'category' AND c.id IN ("
            .'    SELECT DISTINCT cag.category_object_id FROM category_attribute_groups cag'
            .'    JOIN attribute_group_attributes aga ON aga.attribute_group_id = cag.attribute_group_id'
            .'    WHERE aga.attribute_id = ?'
            .' )'
            .' ORDER BY c.path',
            [$attributeId],
        );

        $instanceCountRaw = $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT object_id) FROM object_values WHERE attribute_id = ?',
            [$attributeId],
        );

        return [
            'groups' => $this->normalizeGroupRows($groups),
            'objectTypes' => $this->normalizeObjectTypeRows($objectTypes),
            'categories' => $this->normalizeCategoryRows($categories),
            'instanceCount' => \is_scalar($instanceCountRaw) ? (int) $instanceCountRaw : 0,
        ];
    }

    /**
     * @return array{
     *     directlyAttachedTo: array{
     *         objectTypes: list<array{id: string, code: string, kind: string}>,
     *         categories: list<array{id: string, path: string|null, target_kind: string|null}>
     *     },
     *     attributeCount: int,
     *     affectedInstanceCount: int
     * }
     */
    private function loadAttributeGroupUsage(AttributeGroup $group): array
    {
        $groupId = $group->getId()->toRfc4122();

        $objectTypes = $this->connection->fetchAllAssociative(
            'SELECT ot.id, ot.code, ot.kind FROM object_types ot'
            .' JOIN object_type_attribute_groups otag ON otag.object_type_id = ot.id'
            .' WHERE otag.attribute_group_id = ?'
            .' ORDER BY ot.code',
            [$groupId],
        );

        $categories = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.path::text AS path, ot.kind AS target_kind FROM objects c'
            .' JOIN category_attribute_groups cag ON cag.category_object_id = c.id'
            .' JOIN object_types ot ON ot.id = cag.target_object_type_id'
            .' WHERE cag.attribute_group_id = ?'
            ." AND c.kind = 'category'"
            .' ORDER BY c.path',
            [$groupId],
        );

        $attributeCountRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM attribute_group_attributes WHERE attribute_group_id = ?',
            [$groupId],
        );

        $affectedInstanceCountRaw = $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT ov.object_id) FROM object_values ov'
            .' WHERE ov.attribute_id IN ('
            .'    SELECT aga.attribute_id FROM attribute_group_attributes aga WHERE aga.attribute_group_id = ?'
            .' )',
            [$groupId],
        );

        return [
            'directlyAttachedTo' => [
                'objectTypes' => $this->normalizeObjectTypeRows($objectTypes),
                'categories' => $this->normalizeCategoryAttachmentRows($categories),
            ],
            'attributeCount' => \is_scalar($attributeCountRaw) ? (int) $attributeCountRaw : 0,
            'affectedInstanceCount' => \is_scalar($affectedInstanceCountRaw) ? (int) $affectedInstanceCountRaw : 0,
        ];
    }

    /**
     * @return array{
     *     instanceCount: int,
     *     attributesAttachedCount: int,
     *     attributeGroupsAttachedCount: int,
     *     referencedByApiProfileCount: int,
     *     referencedByCategoryAttachmentCount: int
     * }
     */
    private function loadObjectTypeUsage(ObjectType $type): array
    {
        $typeId = $type->getId()->toRfc4122();

        $instanceCountRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE object_type_id = ?',
            [$typeId],
        );
        $attributesRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM object_type_attributes WHERE object_type_id = ?',
            [$typeId],
        );
        $groupsRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM object_type_attribute_groups WHERE object_type_id = ?',
            [$typeId],
        );
        // ApiProfile.objectTypeIds is a JSONB list of UUID strings; JSONB
        // contains operator (`@>`) checks if the list contains the
        // single-element array.
        $apiProfilesRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM api_profiles WHERE object_type_ids @> ?::jsonb',
            ['["'.$typeId.'"]'],
        );
        $categoryAttachRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM category_attribute_groups WHERE target_object_type_id = ?',
            [$typeId],
        );

        return [
            'instanceCount' => \is_scalar($instanceCountRaw) ? (int) $instanceCountRaw : 0,
            'attributesAttachedCount' => \is_scalar($attributesRaw) ? (int) $attributesRaw : 0,
            'attributeGroupsAttachedCount' => \is_scalar($groupsRaw) ? (int) $groupsRaw : 0,
            'referencedByApiProfileCount' => \is_scalar($apiProfilesRaw) ? (int) $apiProfilesRaw : 0,
            'referencedByCategoryAttachmentCount' => \is_scalar($categoryAttachRaw) ? (int) $categoryAttachRaw : 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, code: string, label: array<string, string>}>
     */
    private function normalizeGroupRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rawId = $row['id'] ?? '';
            $rawCode = $row['code'] ?? '';
            $id = \is_scalar($rawId) ? (string) $rawId : '';
            $code = \is_scalar($rawCode) ? (string) $rawCode : '';
            $label = $row['label'] ?? null;
            if (\is_string($label)) {
                $decoded = json_decode($label, true);
                $label = \is_array($decoded) ? $decoded : [];
            }
            if (!\is_array($label)) {
                $label = [];
            }
            $cleanLabel = [];
            foreach ($label as $k => $v) {
                if (\is_string($k) && \is_string($v)) {
                    $cleanLabel[$k] = $v;
                }
            }
            $out[] = ['id' => $id, 'code' => $code, 'label' => $cleanLabel];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, code: string, kind: string}>
     */
    private function normalizeObjectTypeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => $this->scalarString($row['id'] ?? null),
                'code' => $this->scalarString($row['code'] ?? null),
                'kind' => $this->scalarString($row['kind'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, path: string|null}>
     */
    private function normalizeCategoryRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            $out[] = [
                'id' => $this->scalarString($row['id'] ?? null),
                'path' => \is_string($path) ? $path : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, path: string|null, target_kind: string|null}>
     */
    private function normalizeCategoryAttachmentRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            $kind = $row['target_kind'] ?? null;
            $out[] = [
                'id' => $this->scalarString($row['id'] ?? null),
                'path' => \is_string($path) ? $path : null,
                'target_kind' => \is_string($kind) ? $kind : null,
            ];
        }

        return $out;
    }

    private function scalarString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
