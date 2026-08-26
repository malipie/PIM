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
 *   - Cached in `pim.modeling_cache`, invalidated by tags `pim_usage`
 *     (global) and `pim_usage.<resource>.<id>` (per-row). The shared
 *     invalidator in `ObjectFormSchemaCacheInvalidator` already nukes
 *     this on schema changes; here we just register the same tag set.
 *
 * Batch reads (#3034): `forAttributes()` / `forAttributeGroups()` /
 * `forObjectTypes()` answer a whole list page in ONE round trip per
 * relation instead of N per row. The modeling list pages used to fan out
 * one HTTP request per row (57 attributes = 57 requests), which saturated
 * the FrankenPHP worker pool and pushed unrelated requests — including the
 * detail page the operator had just navigated to — behind a queue tens of
 * seconds deep on a tenant with 1.27M `object_values`.
 *
 * The batch methods share cache keys with the single-item methods, so a
 * list page warms the detail page and vice versa.
 */
final readonly class UsageQueryService
{
    public const string CACHE_TAG = 'pim_usage';

    /**
     * Was 60s. Raised to 300s together with the batch reads (#3034): these
     * counters are advisory ("where is this used?") and every destructive
     * decision goes through {@see self::forAttributeFresh()}, which bypasses
     * the cache entirely. A list page repainting a 4-minute-old instance
     * count costs nothing; recomputing it costs a scan of `object_values`.
     */
    public const int CACHE_TTL_SECONDS = 300;

    /**
     * Guard rail for the batch endpoint: without a ceiling, `?ids=` would be
     * a way to ask for an unbounded `IN (…)` over `object_values`.
     */
    public const int MAX_BATCH_IDS = 500;

    public function __construct(
        private Connection $connection,
        private TagAwareCacheInterface $modelingCache,
        private UsageBatchLoader $batchLoader,
    ) {
    }

    /**
     * @return array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int,
     *     optionCount: int
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
     *     instanceCount: int,
     *     optionCount: int
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
     * Batch counterpart of {@see self::forAttribute()} (#3034).
     *
     * Shares cache keys and tags with the single-item method, so whichever
     * page runs first warms the other. The batch SQL is deferred into the
     * cache callback and memoised across the loop: it runs at most once per
     * call, and not at all when every id is already cached.
     *
     * @param list<string> $ids RFC 4122 attribute ids
     *
     * @return array<string, array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int,
     *     optionCount: int
     * }>
     */
    public function forAttributes(array $ids): array
    {
        $ids = $this->batchLoader->existingIds('attributes', UsageBatchLoader::dedupe($ids));
        if ([] === $ids) {
            return [];
        }

        /** @var array<string, array{groups: list<array{id: string, code: string, label: array<string, string>}>, objectTypes: list<array{id: string, code: string, kind: string}>, categories: list<array{id: string, path: string|null}>, instanceCount: int, optionCount: int}>|null $batch */
        $batch = null;
        $out = [];

        foreach ($ids as $id) {
            /** @var array{groups: list<array{id: string, code: string, label: array<string, string>}>, objectTypes: list<array{id: string, code: string, kind: string}>, categories: list<array{id: string, path: string|null}>, instanceCount: int, optionCount: int} $payload */
            $payload = $this->modelingCache->get(
                \sprintf('pim_usage_attribute_%s', $id),
                function (ItemInterface $item) use ($id, $ids, &$batch): array {
                    $item->expiresAfter(self::CACHE_TTL_SECONDS);
                    $item->tag([self::CACHE_TAG, self::CACHE_TAG.'.attribute.'.$id]);
                    $batch ??= $this->batchLoader->loadAttributeUsageBatch($ids);

                    return $batch[$id] ?? self::emptyAttributeUsage();
                },
            );
            $out[$id] = $payload;
        }

        return $out;
    }

    /**
     * Batch counterpart of {@see self::forAttributeGroup()} (#3034).
     *
     * @param list<string> $ids RFC 4122 attribute group ids
     *
     * @return array<string, array{
     *     directlyAttachedTo: array{
     *         objectTypes: list<array{id: string, code: string, kind: string}>,
     *         categories: list<array{id: string, path: string|null, target_kind: string|null}>
     *     },
     *     attributeCount: int,
     *     affectedInstanceCount: int
     * }>
     */
    public function forAttributeGroups(array $ids): array
    {
        $ids = $this->batchLoader->existingIds('attribute_groups', UsageBatchLoader::dedupe($ids));
        if ([] === $ids) {
            return [];
        }

        /** @var array<string, array{directlyAttachedTo: array{objectTypes: list<array{id: string, code: string, kind: string}>, categories: list<array{id: string, path: string|null, target_kind: string|null}>}, attributeCount: int, affectedInstanceCount: int}>|null $batch */
        $batch = null;
        $out = [];

        foreach ($ids as $id) {
            /** @var array{directlyAttachedTo: array{objectTypes: list<array{id: string, code: string, kind: string}>, categories: list<array{id: string, path: string|null, target_kind: string|null}>}, attributeCount: int, affectedInstanceCount: int} $payload */
            $payload = $this->modelingCache->get(
                \sprintf('pim_usage_attribute_group_%s', $id),
                function (ItemInterface $item) use ($id, $ids, &$batch): array {
                    $item->expiresAfter(self::CACHE_TTL_SECONDS);
                    $item->tag([self::CACHE_TAG, self::CACHE_TAG.'.attribute_group.'.$id]);
                    $batch ??= $this->batchLoader->loadAttributeGroupUsageBatch($ids);

                    return $batch[$id] ?? self::emptyAttributeGroupUsage();
                },
            );
            $out[$id] = $payload;
        }

        return $out;
    }

    /**
     * Batch counterpart of {@see self::forObjectType()} (#3034).
     *
     * @param list<string> $ids RFC 4122 object type ids
     *
     * @return array<string, array{
     *     instanceCount: int,
     *     attributesAttachedCount: int,
     *     attributeGroupsAttachedCount: int,
     *     referencedByApiProfileCount: int,
     *     referencedByCategoryAttachmentCount: int
     * }>
     */
    public function forObjectTypes(array $ids): array
    {
        $ids = $this->batchLoader->existingIds('object_types', UsageBatchLoader::dedupe($ids));
        if ([] === $ids) {
            return [];
        }

        /** @var array<string, array{instanceCount: int, attributesAttachedCount: int, attributeGroupsAttachedCount: int, referencedByApiProfileCount: int, referencedByCategoryAttachmentCount: int}>|null $batch */
        $batch = null;
        $out = [];

        foreach ($ids as $id) {
            /** @var array{instanceCount: int, attributesAttachedCount: int, attributeGroupsAttachedCount: int, referencedByApiProfileCount: int, referencedByCategoryAttachmentCount: int} $payload */
            $payload = $this->modelingCache->get(
                \sprintf('pim_usage_object_type_%s', $id),
                function (ItemInterface $item) use ($id, $ids, &$batch): array {
                    $item->expiresAfter(self::CACHE_TTL_SECONDS);
                    $item->tag([self::CACHE_TAG, self::CACHE_TAG.'.object_type.'.$id]);
                    $batch ??= $this->batchLoader->loadObjectTypeUsageBatch($ids);

                    return $batch[$id] ?? self::emptyObjectTypeUsage();
                },
            );
            $out[$id] = $payload;
        }

        return $out;
    }

    /**
     * @return array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int,
     *     optionCount: int
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

        // #3034 — carried in the usage payload so the modeling list does not
        // need a second per-row fan-out to `/api/attributes/{code}/options`
        // just to render the "N wartości" link.
        $optionCountRaw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM attribute_options WHERE attribute_id = ?',
            [$attributeId],
        );

        return [
            'groups' => UsageRowNormalizer::normalizeGroupRows($groups),
            'objectTypes' => UsageRowNormalizer::normalizeObjectTypeRows($objectTypes),
            'categories' => UsageRowNormalizer::normalizeCategoryRows($categories),
            'instanceCount' => \is_scalar($instanceCountRaw) ? (int) $instanceCountRaw : 0,
            'optionCount' => \is_scalar($optionCountRaw) ? (int) $optionCountRaw : 0,
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
                'objectTypes' => UsageRowNormalizer::normalizeObjectTypeRows($objectTypes),
                'categories' => UsageRowNormalizer::normalizeCategoryAttachmentRows($categories),
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
     * @return array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int,
     *     optionCount: int
     * }
     */
    private static function emptyAttributeUsage(): array
    {
        return [
            'groups' => [],
            'objectTypes' => [],
            'categories' => [],
            'instanceCount' => 0,
            'optionCount' => 0,
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
    private static function emptyAttributeGroupUsage(): array
    {
        return [
            'directlyAttachedTo' => ['objectTypes' => [], 'categories' => []],
            'attributeCount' => 0,
            'affectedInstanceCount' => 0,
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
    private static function emptyObjectTypeUsage(): array
    {
        return [
            'instanceCount' => 0,
            'attributesAttachedCount' => 0,
            'attributeGroupsAttachedCount' => 0,
            'referencedByApiProfileCount' => 0,
            'referencedByCategoryAttachmentCount' => 0,
        ];
    }
}
