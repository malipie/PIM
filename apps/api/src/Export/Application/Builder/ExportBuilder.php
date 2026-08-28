<?php

declare(strict_types=1);

namespace App\Export\Application\Builder;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectRelationRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Export\Domain\Entity\ExportSession;
use App\Identity\Contracts\Policy\AttributePermissionReader;
use App\Shared\Domain\Tenant;
use Generator;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Traversable;

/**
 * Core export engine — turns a stream of CatalogObjects into XLSX / CSV
 * row arrays.
 *
 * Contract (PRD-PIM-exports.md §5, §8):
 *   - Input is an `iterable<CatalogObject>` so the caller (sync runner
 *     EXP-05, async handler EXP-06) owns chunking + `EntityManager::clear()`.
 *     This keeps memory bounded under FrankenPHP worker mode
 *     (CLAUDE.md §3.10) without forcing the builder to be repository-aware.
 *   - Output is a Generator yielding `array<string,string>`: keys are the
 *     resolved column keys (`sku`, `description.pl`), values are
 *     serialised strings (PRD §8 — pipe for multi, blank for null, etc.).
 *   - The first call to {@see build()} runs the {@see ColumnResolver}
 *     once up-front; lookups inside the loop are O(1) per column.
 *
 * What the builder does NOT do:
 *   - Variant fan-out. The caller materialises masters + variants in the
 *     desired order before passing them in. Each variant carries
 *     `parent_sku` via {@see CatalogObject::getParent()} so the export
 *     row reflects flat layout (decyzja Fali 5 α, PRD §8.3).
 *   - Filter resolution. Selection / filter snapshot / all targets are
 *     resolved by the caller against the catalog list service.
 *   - Asset URL minting. {@see ValueSerializer} emits `asset_id` for the
 *     MVP; IMP-18 (#604) closes the round-trip once the CDN base URL
 *     lands. Bumping that to a real URL is a single-call swap here.
 */
final class ExportBuilder
{
    public function __construct(
        private readonly ObjectValueRepositoryInterface $values,
        private readonly ObjectCategoryRepositoryInterface $categories,
        private readonly ColumnResolver $columnResolver,
        private readonly ValueSerializer $serializer,
        private readonly ChannelResolverInterface $channels,
        private readonly ObjectRelationRepositoryInterface $relations,
        private readonly AttributeRepositoryInterface $attributes,
        private readonly AttributePermissionReader $attributePermissions,
    ) {
    }

    /**
     * @param iterable<CatalogObject> $objects
     *
     * @return Generator<int, array<string, string>>
     */
    public function build(iterable $objects, ExportSession $session): Generator
    {
        $tenant = $session->getTenant();
        if (null === $tenant) {
            throw new LogicException('Export session must carry a tenant before build().');
        }

        $channelCodes = $session->getChannels() ?? [];
        $columns = $this->columnResolver->resolve($session->getSelectedColumns(), $channelCodes);

        // #1229: ObjectValue rows key the channel scope by UUID, so resolve
        // each session channel code to its id once up-front. A code with no
        // matching tenant channel is dropped here and degrades to a blank
        // cell in cellFor() (stale profile, R-47) rather than 500-ing.
        $channelIdByCode = [];
        foreach ($channelCodes as $code) {
            $id = $this->channels->resolveId($code, $tenant);
            if (null !== $id) {
                $channelIdByCode[$code] = $id->toRfc4122();
            }
        }

        // IMP2-1.6 (R-47): a channel-scoped column whose channel no longer
        // resolves fails the whole export loudly. The pre-1.6 silent blank
        // column, combined with clear_if_empty downstream, could wipe a
        // destination. Preflight catches this as a 422; this is the
        // defensive guard for a bypassed preflight.
        $this->assertChannelColumnsResolvable($columns, $channelIdByCode);

        // IMP2-1.8: resolve the attribute behind each attribute column once so
        // cellFor can route Relation/Reference columns to object_relations.
        $attributeMap = $this->resolveColumnAttributes($columns, $tenant);

        // AUD-016 (#1632): the caller hands us a bounded keyset PAGE (the sync
        // runner streams scope-All/Selected/Filter in CLEAR_INTERVAL-sized
        // pages, EntityManager::clear() between them). Materialise the page
        // once and batch-load its object_values / relations / categories in a
        // fixed number of queries per page instead of one-per-object — the
        // pre-1632 lazy `findByObject`/`findBySourceAndAttribute`/`findByProduct`
        // path issued 100k-150k round-trips for a 50k export (PRD §11.2).
        $page = $objects instanceof Traversable ? iterator_to_array($objects, false) : array_values($objects);
        if ([] === $page) {
            return;
        }

        $pageIds = array_map(static fn (CatalogObject $o): string => $o->getId()->toRfc4122(), $page);
        $valuesByObjectId = $this->values->findByObjectIds(
            array_map(static fn (CatalogObject $o): Uuid => $o->getId(), $page),
        );
        $relationCodesByColumn = $this->prefetchRelationCodes($pageIds, $attributeMap);
        // #2796 — resolve every column's view permission once per page.
        // `cell()` asks per column PER ROW, so on the interactive path (where
        // a domain user is present) a 60-column export used to warm the
        // policy one attribute at a time. Pre-resolving turns that into a
        // single batch; system-context runs skip it entirely, exactly as the
        // per-cell guard below does.
        $this->prefetchAttributeViewPermissions($attributeMap);
        $categoriesByObjectId = [];
        if ($this->needsCategoryColumn($columns)) {
            // XMLF-P3-03 — variants are not categorised independently: an
            // uncategorised child inherits its master's categories (flat feed
            // items need the marketplace category on EVERY variant row), so
            // the prefetch covers the page's parents too.
            $parentIds = [];
            foreach ($page as $object) {
                $parent = $object->getParent();
                if (null !== $parent) {
                    $parentIds[$parent->getId()->toRfc4122()] = true;
                }
            }
            $categoriesByObjectId = $this->prefetchCategories(
                array_values(array_unique([...$pageIds, ...array_keys($parentIds)])),
            );
        }

        $empty = ['codes' => [], 'ids' => []];
        $primaryLocale = $tenant->getPrimaryLocale();
        foreach ($page as $object) {
            $objectId = $object->getId()->toRfc4122();
            $categories = $categoriesByObjectId[$objectId] ?? $empty;
            $parent = $object->getParent();
            if ([] === $categories['ids'] && null !== $parent) {
                $categories = $categoriesByObjectId[$parent->getId()->toRfc4122()] ?? $empty;
            }
            yield $this->renderRow(
                $object,
                $columns,
                $channelIdByCode,
                $primaryLocale,
                $attributeMap,
                $valuesByObjectId[$objectId] ?? [],
                $relationCodesByColumn,
                $categories,
            );
        }
    }

    /**
     * #2796 — warm the per-attribute view decisions for the whole column set
     * in one batch.
     *
     * {@see cell()} consults `canViewAttribute()` for every attribute column
     * of every row. The policy memoises decisions per request, so the cost
     * was never per row — but it WAS per distinct attribute, because each
     * first-time lookup resolved a single id. A 60-column export therefore
     * warmed the cache with 60 separate resolutions before settling.
     *
     * Skipped in system contexts (async export handler, sync runner): those
     * carry no domain user, `cell()` short-circuits on
     * `isAttributePermissionEnforced()` before it ever asks, and resolving
     * here would only burn a query on an answer nobody reads.
     *
     * @param array<string, Attribute> $attributeMap
     */
    private function prefetchAttributeViewPermissions(array $attributeMap): void
    {
        if ([] === $attributeMap || !$this->attributePermissions->isAttributePermissionEnforced()) {
            return;
        }

        $this->attributePermissions->canViewAttributes(
            array_values(array_map(static fn (Attribute $a): Uuid => $a->getId(), $attributeMap)),
        );
    }

    /**
     * AUD-016 (#1632) — one `findBySourceIdsAndAttribute()` per relation/reference
     * column for the whole page, reduced to the pipe-join inputs: target CODES
     * keyed by `attributeCode => [sourceId => codes]`. Non-relation columns and
     * pages with no relation columns issue no query. The ObjectRelation graph is
     * consumed here so the row pipeline only ever handles strings.
     *
     * @param list<string>             $pageIds
     * @param array<string, Attribute> $attributeMap
     *
     * @return array<string, array<string, list<string>>>
     */
    private function prefetchRelationCodes(array $pageIds, array $attributeMap): array
    {
        $byColumn = [];
        foreach ($attributeMap as $code => $attribute) {
            if (AttributeType::Relation !== $attribute->getType() && AttributeType::Reference !== $attribute->getType()) {
                continue;
            }
            $bySource = [];
            foreach ($this->relations->findBySourceIdsAndAttribute($pageIds, $attribute) as $sourceId => $links) {
                $bySource[$sourceId] = array_map(static fn ($link): string => $link->getTarget()->getCode(), $links);
            }
            $byColumn[$code] = $bySource;
        }

        return $byColumn;
    }

    /**
     * AUD-016 (#1632) — one `findByProductIds()` for the whole page, reduced to
     * category CODES + IDS keyed by object id. The ObjectCategory graph is
     * consumed here so the row pipeline only ever handles strings. IDs feed the
     * `category_ids` built-in (XMLF-P3-03) — the feed generator resolves them
     * to marketplace external codes through the Channel contract.
     *
     * @param list<string> $pageIds
     *
     * @return array<string, array{codes: list<string>, ids: list<string>}>
     */
    private function prefetchCategories(array $pageIds): array
    {
        $byObject = [];
        foreach ($this->categories->findByProductIds($pageIds) as $objectId => $assignments) {
            $codes = [];
            $ids = [];
            foreach ($assignments as $assignment) {
                $codes[] = $assignment->getCategory()->getCode();
                $ids[] = $assignment->getCategory()->getId()->toRfc4122();
            }
            $byObject[$objectId] = ['codes' => $codes, 'ids' => $ids];
        }

        return $byObject;
    }

    /**
     * @param array<int, ColumnDefinition> $columns
     */
    private function needsCategoryColumn(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column->isBuiltIn() && \in_array($column->code, ['category', 'category_ids'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, ColumnDefinition> $columns
     *
     * @return array<string, Attribute>
     */
    private function resolveColumnAttributes(array $columns, Tenant $tenant): array
    {
        $map = [];
        foreach ($columns as $column) {
            if (!$column->isAttribute() || isset($map[$column->code])) {
                continue;
            }
            $attribute = $this->attributes->findByCode($column->code, $tenant);
            if (null !== $attribute) {
                $map[$column->code] = $attribute;
            }
        }

        return $map;
    }

    /**
     * @param array<int, ColumnDefinition> $columns
     * @param array<string, string>        $channelIdByCode channel code => UUID RFC 4122 (#1229)
     */
    private function assertChannelColumnsResolvable(array $columns, array $channelIdByCode): void
    {
        $unresolved = [];
        foreach ($columns as $column) {
            if (null !== $column->channel && !isset($channelIdByCode[$column->channel])) {
                $unresolved[$column->channel] = true;
            }
        }

        if ([] !== $unresolved) {
            throw new UnresolvedExportChannelException(array_keys($unresolved));
        }
    }

    /**
     * @param array<int, ColumnDefinition>                  $columns
     * @param array<string, string>                         $channelIdByCode       channel code => UUID RFC 4122 (#1229)
     * @param array<string, Attribute>                      $attributeMap          attribute column code => Attribute (#1471)
     * @param list<ObjectValue>                             $objectValues          this object's prefetched values (#1632)
     * @param array<string, array<string, list<string>>>    $relationCodesByColumn attributeCode => [sourceId => target codes] (#1632)
     * @param array{codes: list<string>, ids: list<string>} $categories            this object's prefetched categories (#1632, XMLF-P3-03)
     *
     * @return array<string, string>
     */
    private function renderRow(
        CatalogObject $object,
        array $columns,
        array $channelIdByCode,
        string $primaryLocale,
        array $attributeMap,
        array $objectValues,
        array $relationCodesByColumn,
        array $categories,
    ): array {
        $valueIndex = $this->indexValuesByObject($objectValues);
        $row = [];

        foreach ($columns as $column) {
            $row[$column->key] = $this->cellFor($object, $column, $valueIndex, $channelIdByCode, $primaryLocale, $attributeMap, $relationCodesByColumn, $categories);
        }

        return $row;
    }

    /**
     * @param list<ObjectValue> $objectValues
     *
     * @return array<string, ObjectValue>
     */
    private function indexValuesByObject(array $objectValues): array
    {
        $index = [];
        foreach ($objectValues as $value) {
            $key = $this->indexKey(
                $value->getAttribute()->getCode(),
                $value->getLocale(),
                null !== $value->getChannelId() ? $value->getChannelId()->toRfc4122() : null,
            );
            $index[$key] = $value;
        }

        return $index;
    }

    private function indexKey(string $code, ?string $locale, ?string $channelId): string
    {
        return sprintf('%s|%s|%s', $code, $locale ?? '', $channelId ?? '');
    }

    /**
     * @param array<string, ObjectValue>                    $valueIndex
     * @param array<string, string>                         $channelIdByCode       channel code => UUID RFC 4122 (#1229)
     * @param array<string, Attribute>                      $attributeMap          attribute column code => Attribute (#1471)
     * @param array<string, array<string, list<string>>>    $relationCodesByColumn attributeCode => [sourceId => target codes] (#1632)
     * @param array{codes: list<string>, ids: list<string>} $categories            this object's prefetched categories (#1632, XMLF-P3-03)
     */
    private function cellFor(
        CatalogObject $object,
        ColumnDefinition $column,
        array $valueIndex,
        array $channelIdByCode,
        string $primaryLocale,
        array $attributeMap,
        array $relationCodesByColumn,
        array $categories,
    ): string {
        if ($column->isBuiltIn()) {
            return $this->builtIn($object, $column->code, $categories);
        }

        $attribute = $attributeMap[$column->code] ?? null;

        // AUD-008 (#1578): never export an attribute the caller may not view
        // (3-state per-attribute permission, PRD §3.5). Emit a blank cell —
        // same shape as a stale/missing column — so the row stays well-formed
        // without leaking the value. Built-in columns above are not
        // attribute-permission subjects. Unknown codes (no resolved
        // Attribute) fall through to the existing blank-cell handling.
        //
        // Only enforced when a domain user is present. The sync runner
        // (EXP-05) and async handler (EXP-06) reach this from system contexts
        // that carry no security token — the same way the write path
        // ({@see \App\Catalog\Application\ObjectAttributesUpserter}) gates on
        // isAttributePermissionEnforced(). Without that guard the
        // anonymous-→-restricted default of canViewAttribute() would blank
        // every attribute cell of a legitimate export (CustomModuleExport,
        // GoldenRoundTrip).
        if ($attribute instanceof Attribute
            && $this->attributePermissions->isAttributePermissionEnforced()
            && !$this->attributePermissions->canViewAttribute($attribute->getId())) {
            return '';
        }

        // IMP2-1.8 (D5): Relation/Reference columns emit pipe-joined target
        // CODES read from object_relations (symmetry with the import, which
        // writes relations there — not as ObjectValue). AUD-016 (#1632): the
        // target codes are batch-prefetched per page, so this is a map lookup
        // rather than a per-object query.
        if ($attribute instanceof Attribute
            && (AttributeType::Relation === $attribute->getType() || AttributeType::Reference === $attribute->getType())) {
            $codes = $relationCodesByColumn[$column->code][$object->getId()->toRfc4122()] ?? [];

            return implode(ValueSerializer::MULTI_VALUE_GLUE, $codes);
        }

        // #1229: a channel-scoped column narrows the lookup to the channel's
        // UUID. Resolvability is guaranteed by assertChannelColumnsResolvable().
        $channelId = null !== $column->channel ? ($channelIdByCode[$column->channel] ?? null) : null;

        $value = $valueIndex[$this->indexKey($column->code, $column->locale, $channelId)] ?? null;

        // #1146 fan-out: the writer collapses the PRIMARY locale into the
        // global (locale=NULL) row, so a `name.pl` column (pl primary) finds
        // nothing under its own locale — fall back to the global value,
        // keeping the channel scope. Non-primary locales never fan out: that
        // would leak the primary value into a locale that has none and break
        // the round-trip.
        if (null === $value && null !== $column->locale && $column->locale === $primaryLocale) {
            $value = $valueIndex[$this->indexKey($column->code, null, $channelId)] ?? null;
        }

        // Stale profile / missing attribute (R-47 from PRD §14). Don't
        // 500 — emit blank cell so the rest of the row stays useful.
        if (null === $value) {
            return '';
        }

        return $this->serializer->serialize($value);
    }

    /**
     * @param array{codes: list<string>, ids: list<string>} $categories prefetched per page (#1632, XMLF-P3-03)
     */
    private function builtIn(CatalogObject $object, string $code, array $categories): string
    {
        return match ($code) {
            'sku' => $this->serializer->serializeScalar($object->getCode()),
            'parent_sku' => $this->serializer->serializeScalar($object->getParent()?->getCode()),
            'status' => $this->serializer->serializeScalar($object->getStatus()),
            'enabled' => $this->serializer->serializeScalar($object->isEnabled()),
            'completeness_pct' => $this->serializer->serializeScalar($object->getCompletenessPct()),
            'created_at' => $this->serializer->serializeScalar($object->getCreatedAt()),
            'updated_at' => $this->serializer->serializeScalar($object->getUpdatedAt()),
            'category' => implode(ValueSerializer::MULTI_VALUE_GLUE, $categories['codes']),
            // XMLF-P3-03 — master category ids for the feed generator's
            // channel external-code resolution (bare cross-BC ids, ADR-0015).
            'category_ids' => implode(ValueSerializer::MULTI_VALUE_GLUE, $categories['ids']),
            'variant_axes' => $this->serializeVariantAxes($object),
            default => '',
        };
    }

    /**
     * IMP2-1.8 — serialise the master's variant axes to the round-trippable
     * full shape `code:value,value|code:value`. Empty for non-masters /
     * objects without declared axes.
     */
    private function serializeVariantAxes(CatalogObject $object): string
    {
        $axes = $object->getVariantAxes();
        if (null === $axes || [] === $axes) {
            return '';
        }

        $parts = [];
        foreach ($axes as $axis) {
            $code = $axis['code'] ?? null;
            if (!\is_string($code) || '' === $code) {
                continue;
            }
            $values = $axis['values'] ?? [];
            $valueList = \is_array($values)
                ? array_values(array_filter(array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $values), static fn (string $v): bool => '' !== $v))
                : [];
            $parts[] = $code.':'.implode(',', $valueList);
        }

        return implode(ValueSerializer::MULTI_VALUE_GLUE, $parts);
    }
}
