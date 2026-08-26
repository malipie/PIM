<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Usage;

use App\Shared\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * #3034 — the SQL behind the batched `where-used` reads.
 *
 * Split out of {@see UsageQueryService} so that class stays about cache
 * orchestration while this one stays about queries. The whole point of these
 * methods is that a modeling list page costs one round trip per relation
 * instead of one per row: on a tenant with 1.27M `object_values` the per-row
 * shape cost 0.7-6.7s each and saturated the FrankenPHP worker pool, pushing
 * unrelated requests tens of seconds behind a queue.
 */
final readonly class UsageBatchLoader
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * One query per relation for the whole id set, instead of four queries
     * per id. The `instanceCount` query is the one that matters: on a tenant
     * with 1.27M `object_values` a single `COUNT(DISTINCT object_id)` costs
     * hundreds of milliseconds, so 57 of them (one modeling list page) cost
     * tens of seconds of worker time.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{
     *     groups: list<array{id: string, code: string, label: array<string, string>}>,
     *     objectTypes: list<array{id: string, code: string, kind: string}>,
     *     categories: list<array{id: string, path: string|null}>,
     *     instanceCount: int,
     *     optionCount: int
     * }>
     */
    public function loadAttributeUsageBatch(array $ids): array
    {
        $groups = self::bucketBy($this->fetchByIds(
            'SELECT j.attribute_id, g.id, g.code, g.label FROM attribute_groups g'
            .' JOIN attribute_group_attributes j ON j.attribute_group_id = g.id'
            .' WHERE j.attribute_id IN (:ids)'
            .' ORDER BY j.attribute_id, g.code',
            $ids,
        ), 'attribute_id');

        $objectTypes = self::bucketBy($this->fetchByIds(
            'SELECT DISTINCT ota.attribute_id, ot.id, ot.code, ot.kind FROM object_types ot'
            .' JOIN object_type_attributes ota ON ota.object_type_id = ot.id'
            .' WHERE ota.attribute_id IN (:ids)'
            .' ORDER BY ota.attribute_id, ot.code',
            $ids,
        ), 'attribute_id');

        $categories = self::bucketBy($this->fetchByIds(
            'SELECT DISTINCT aga.attribute_id, c.id, c.path::text AS path FROM objects c'
            .' JOIN category_attribute_groups cag ON cag.category_object_id = c.id'
            .' JOIN attribute_group_attributes aga ON aga.attribute_group_id = cag.attribute_group_id'
            ." WHERE c.kind = 'category' AND aga.attribute_id IN (:ids)"
            .' ORDER BY aga.attribute_id, path',
            $ids,
        ), 'attribute_id');

        $instanceCounts = self::bucketCounts($this->fetchByIds(
            'SELECT attribute_id, COUNT(DISTINCT object_id) AS c FROM object_values'
            .' WHERE attribute_id IN (:ids) GROUP BY attribute_id',
            $ids,
        ), 'attribute_id');

        $optionCounts = self::bucketCounts($this->fetchByIds(
            'SELECT attribute_id, COUNT(*) AS c FROM attribute_options'
            .' WHERE attribute_id IN (:ids) GROUP BY attribute_id',
            $ids,
        ), 'attribute_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'groups' => UsageRowNormalizer::normalizeGroupRows($groups[$id] ?? []),
                'objectTypes' => UsageRowNormalizer::normalizeObjectTypeRows($objectTypes[$id] ?? []),
                'categories' => UsageRowNormalizer::normalizeCategoryRows($categories[$id] ?? []),
                'instanceCount' => $instanceCounts[$id] ?? 0,
                'optionCount' => $optionCounts[$id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $ids
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
    public function loadAttributeGroupUsageBatch(array $ids): array
    {
        $objectTypes = self::bucketBy($this->fetchByIds(
            'SELECT otag.attribute_group_id, ot.id, ot.code, ot.kind FROM object_types ot'
            .' JOIN object_type_attribute_groups otag ON otag.object_type_id = ot.id'
            .' WHERE otag.attribute_group_id IN (:ids)'
            .' ORDER BY otag.attribute_group_id, ot.code',
            $ids,
        ), 'attribute_group_id');

        $categories = self::bucketBy($this->fetchByIds(
            'SELECT cag.attribute_group_id, c.id, c.path::text AS path, ot.kind AS target_kind'
            .' FROM objects c'
            .' JOIN category_attribute_groups cag ON cag.category_object_id = c.id'
            .' JOIN object_types ot ON ot.id = cag.target_object_type_id'
            .' WHERE cag.attribute_group_id IN (:ids)'
            ." AND c.kind = 'category'"
            .' ORDER BY cag.attribute_group_id, path',
            $ids,
        ), 'attribute_group_id');

        $attributeCounts = self::bucketCounts($this->fetchByIds(
            'SELECT attribute_group_id, COUNT(*) AS c FROM attribute_group_attributes'
            .' WHERE attribute_group_id IN (:ids) GROUP BY attribute_group_id',
            $ids,
        ), 'attribute_group_id');

        // One pass over `object_values` for every requested group, instead of
        // one `COUNT(DISTINCT …) WHERE attribute_id IN (subquery)` per group.
        $affectedCounts = self::bucketCounts($this->fetchByIds(
            'SELECT aga.attribute_group_id, COUNT(DISTINCT ov.object_id) AS c'
            .' FROM attribute_group_attributes aga'
            .' JOIN object_values ov ON ov.attribute_id = aga.attribute_id'
            .' WHERE aga.attribute_group_id IN (:ids)'
            .' GROUP BY aga.attribute_group_id',
            $ids,
        ), 'attribute_group_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'directlyAttachedTo' => [
                    'objectTypes' => UsageRowNormalizer::normalizeObjectTypeRows($objectTypes[$id] ?? []),
                    'categories' => UsageRowNormalizer::normalizeCategoryAttachmentRows($categories[$id] ?? []),
                ],
                'attributeCount' => $attributeCounts[$id] ?? 0,
                'affectedInstanceCount' => $affectedCounts[$id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array{
     *     instanceCount: int,
     *     attributesAttachedCount: int,
     *     attributeGroupsAttachedCount: int,
     *     referencedByApiProfileCount: int,
     *     referencedByCategoryAttachmentCount: int
     * }>
     */
    public function loadObjectTypeUsageBatch(array $ids): array
    {
        $instanceCounts = self::bucketCounts($this->fetchByIds(
            'SELECT object_type_id, COUNT(*) AS c FROM objects'
            .' WHERE object_type_id IN (:ids) GROUP BY object_type_id',
            $ids,
        ), 'object_type_id');

        $attributeCounts = self::bucketCounts($this->fetchByIds(
            'SELECT object_type_id, COUNT(*) AS c FROM object_type_attributes'
            .' WHERE object_type_id IN (:ids) GROUP BY object_type_id',
            $ids,
        ), 'object_type_id');

        $groupCounts = self::bucketCounts($this->fetchByIds(
            'SELECT object_type_id, COUNT(*) AS c FROM object_type_attribute_groups'
            .' WHERE object_type_id IN (:ids) GROUP BY object_type_id',
            $ids,
        ), 'object_type_id');

        // `ApiProfile.objectTypeIds` is a JSONB list of UUID strings. Unnesting
        // it once and grouping beats one `@>` containment probe per id. The
        // `jsonb_typeof` guard keeps a malformed/legacy row from aborting the
        // whole query — `jsonb_array_elements_text` raises on a non-array.
        $apiProfileCounts = self::bucketCounts($this->fetchByIds(
            'SELECT e.value AS object_type_id, COUNT(*) AS c FROM api_profiles p'
            .' CROSS JOIN LATERAL jsonb_array_elements_text(p.object_type_ids) AS e(value)'
            ." WHERE jsonb_typeof(p.object_type_ids) = 'array' AND e.value IN (:ids)"
            .' GROUP BY e.value',
            $ids,
        ), 'object_type_id');

        $categoryAttachCounts = self::bucketCounts($this->fetchByIds(
            'SELECT target_object_type_id AS object_type_id, COUNT(*) AS c'
            .' FROM category_attribute_groups'
            .' WHERE target_object_type_id IN (:ids) GROUP BY target_object_type_id',
            $ids,
        ), 'object_type_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'instanceCount' => $instanceCounts[$id] ?? 0,
                'attributesAttachedCount' => $attributeCounts[$id] ?? 0,
                'attributeGroupsAttachedCount' => $groupCounts[$id] ?? 0,
                'referencedByApiProfileCount' => $apiProfileCounts[$id] ?? 0,
                'referencedByCategoryAttachmentCount' => $categoryAttachCounts[$id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Narrow a requested id set to the rows that actually exist **in the
     * caller's tenant**, using the Postgres RLS predicate on the table.
     *
     * This is a security requirement, not an optimisation. `pim.modeling_cache`
     * is not tenant-namespaced (keys are `pim_usage_<resource>_<uuid>`), which
     * is safe for the single-item endpoints because they resolve the entity
     * through a tenant-filtered repository and 404 before touching the cache.
     * A batch read has no such gate, so without this filter a caller could
     * name another tenant's id, get a zeroed payload computed under its own
     * RLS scope, and have that zero cached under the owning tenant's key —
     * poisoning the real owner's counters for the whole TTL.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function existingIds(string $table, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Explicit tenant predicate rather than leaning on Postgres RLS alone.
        // The per-item endpoints resolve their entity through a tenant-filtered
        // Doctrine repository before doing anything, so they are safe at the ORM
        // layer too; a raw DBAL batch has no such gate, and defence in depth is
        // the point of having both. Same posture as SmartFilterPresetController.
        $tenantId = $this->tenantContext->get()?->getId()?->toRfc4122();
        if (null === $tenantId) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT id FROM %s WHERE tenant_id = :tenant AND id IN (:ids)', $table),
            ['tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $found = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (\is_scalar($id)) {
                $found[(string) $id] = true;
            }
        }

        // Preserve the caller's ordering rather than the database's.
        return array_values(array_filter($ids, static fn (string $id): bool => isset($found[$id])));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function fetchByIds(string $sql, array $ids): array
    {
        return $this->connection->fetchAllAssociative(
            $sql,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * Group rows by a key column and drop that column from each row, so the
     * buckets can be handed straight to the existing single-row normalizers.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function bucketBy(array $rows, string $keyColumn): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row[$keyColumn] ?? null;
            if (!\is_scalar($key)) {
                continue;
            }
            unset($row[$keyColumn]);
            $out[(string) $key][] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private static function bucketCounts(array $rows, string $keyColumn): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row[$keyColumn] ?? null;
            if (!\is_scalar($key)) {
                continue;
            }
            $count = $row['c'] ?? null;
            $out[(string) $key] = is_numeric($count) ? (int) $count : 0;
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function dedupe(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
