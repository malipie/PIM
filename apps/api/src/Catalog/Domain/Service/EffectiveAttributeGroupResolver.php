<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service;

use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\EffectiveAttributeGroupQueryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.4 (#259) — resolves the *effective* list of AttributeGroups for an
 * object's form schema by layering three sources per ADR-012 + plan §3.5:
 *
 *   1. **Globalne grupy ObjectType** (`object_type_attribute_groups`) — the
 *      baseline list every object of a given kind sees, irrespective of
 *      category. System audit attributes are not included unless an
 *      AttributeGroup containing them is explicitly attached.
 *   2. **Grupy dziedziczone z drzewa kategorii** (`category_attribute_groups`)
 *      — for `kind='category'` objects, walked from root → leaf via the
 *      `parent` self-reference. Each ancestor contributes the groups
 *      declared with `target_object_type_id = <object's ObjectType>`.
 *   3. **Grupy declared on the object's *own* category-level ancestors**
 *      when the object IS a category — same walk, but uses the object's
 *      own `target_object_type_id` so the form shows what attributes a
 *      future child would inherit (see {@see resolveForCategoryPreview}).
 *
 * **Out of scope (Faza 1+ kandydat):**
 *   - Per-object ad-hoc groups (no `object_attribute_groups` table yet).
 *
 * **Product↔category inheritance (PCAT-03 / epic UI-10):** when the
 * resolved object is `kind=Product`, the resolver fetches the product's
 * assignments via {@see ObjectCategoryRepositoryInterface}, walks each
 * assigned category's ancestor chain (root→leaf via `parent_id` self-FK),
 * deduplicates the union, and merges declared groups exactly the same way
 * the category branch does. A product with zero assignments still works
 * — the category merge is a no-op so the result is just the ObjectType
 * groups (backward compatible with the pre-PCAT behaviour).
 *
 * **Deduplication:** outputs are unique by `AttributeGroup.id`. When the
 * same group is declared at multiple levels, the higher-priority source
 * wins (ObjectType global beats category, root category beats descendant
 * — first occurrence kept). Order within the result list follows source
 * priority + `position`.
 *
 * **Thread safety / caching:** the service is stateless; cache is wrapped
 * around it by {@see \App\Catalog\Application\Query\GetObjectFormSchema\GetObjectFormSchemaHandler}
 * via the modeling cache pool. The resolver itself never caches — it
 * remains the source of truth for tests + invalidation paths.
 */
readonly class EffectiveAttributeGroupResolver
{
    public function __construct(
        private EffectiveAttributeGroupQueryInterface $groupQuery,
        private ObjectCategoryRepositoryInterface $productCategories,
    ) {
    }

    /**
     * Resolve effective groups for an existing object's form.
     *
     * ADR-014 / MOD-03 (#895) — two layers:
     *
     * 1. **Base** — `loadObjectTypeGroups($type)` always returns the
     *    ObjectType's directly attached groups. Applies to every kind
     *    (Product, Category, Asset, custom) and fixes #3-#28: a Category
     *    instance now renders its own base groups instead of an empty
     *    form when no parent categories carry declared groups.
     *
     * 2. **Primary-category overlay** — only when `ObjectType.is_categorizable
     *    = true` and the object has a primary `ObjectCategory` assignment.
     *    The primary's ancestor chain (root → leaf, including the primary
     *    itself) is walked and `CategoryAttributeGroup` rows targeting this
     *    ObjectType are merged into the result. Secondary category
     *    assignments are intentionally ignored — they classify the object,
     *    not its attribute set.
     *
     * For non-categorizable ObjectTypes (Category, Asset, custom kinds with
     * `is_categorizable=false`) layer 2 is skipped entirely. For
     * categorizable objects without a primary the result is just the base
     * layer (no overlay until the operator picks one).
     *
     * @return list<AttributeGroup>
     */
    public function resolve(CatalogObject $object): array
    {
        $type = $object->getObjectType();
        $groups = $this->loadObjectTypeGroups($type);

        if (!$type->isCategorizable()) {
            return array_values($groups);
        }

        $primary = $this->productCategories->findPrimary($object);
        if (null === $primary) {
            return array_values($groups);
        }

        $ancestorIds = $this->collectCategoryAncestorIds($primary->getCategory(), includeSelf: true);
        if ([] !== $ancestorIds) {
            $this->mergeCategoryGroups($groups, $ancestorIds, $type);
        }

        return array_values($groups);
    }

    /**
     * Resolve effective groups for a hypothetical new object placed under
     * an optional category anchor — used by the admin "preview" panel
     * before the row exists (#UI-08.10 wizard, #UI-08.14 inheritance
     * preview).
     *
     * @return list<AttributeGroup>
     */
    public function resolveForCategoryPreview(ObjectType $type, ?CatalogObject $categoryAnchor): array
    {
        $groups = $this->loadObjectTypeGroups($type);

        if (null !== $categoryAnchor && ObjectKind::Category === $categoryAnchor->getKind()) {
            $ancestorIds = $this->collectCategoryAncestorIds($categoryAnchor, includeSelf: true);
            $this->mergeCategoryGroups($groups, $ancestorIds, $type);
        }

        return array_values($groups);
    }

    /**
     * #891 — multi-category preview for the products/new flow. Walks each
     * supplied category's ancestor chain (including the category itself),
     * unions the IDs, and merges declared groups targeting the requested
     * ObjectType. Categories from a different kind are silently dropped —
     * the controller layer guards against cross-kind UUIDs upstream.
     *
     * @param list<CatalogObject> $categoryAnchors
     *
     * @return list<AttributeGroup>
     */
    public function resolveForCategoryList(ObjectType $type, array $categoryAnchors): array
    {
        $groups = $this->loadObjectTypeGroups($type);

        if ([] === $categoryAnchors) {
            return array_values($groups);
        }

        $ancestorMap = [];
        foreach ($categoryAnchors as $anchor) {
            if (ObjectKind::Category !== $anchor->getKind()) {
                continue;
            }
            foreach ($this->collectCategoryAncestorIds($anchor, includeSelf: true) as $id) {
                $ancestorMap[$id->toRfc4122()] = $id;
            }
        }

        if ([] !== $ancestorMap) {
            $this->mergeCategoryGroups($groups, array_values($ancestorMap), $type);
        }

        return array_values($groups);
    }

    /**
     * @return array<string, AttributeGroup> keyed by group UUID for dedup
     */
    private function loadObjectTypeGroups(ObjectType $type): array
    {
        $groups = [];
        foreach ($this->groupQuery->findObjectTypeGroups($type) as $junction) {
            $group = $junction->getAttributeGroup();
            $groups[$group->getId()->toRfc4122()] = $group;
        }

        return $groups;
    }

    /**
     * @param array<string, AttributeGroup> $groups      in/out — extended with category-derived groups (first occurrence wins)
     * @param list<Uuid>                    $categoryIds ordered root → leaf
     */
    private function mergeCategoryGroups(array &$groups, array $categoryIds, ObjectType $targetType): void
    {
        if ([] === $categoryIds) {
            return;
        }

        foreach ($this->groupQuery->findCategoryGroups($categoryIds, $targetType) as $junction) {
            $group = $junction->getAttributeGroup();
            $key = $group->getId()->toRfc4122();
            if (isset($groups[$key])) {
                continue;
            }
            $groups[$key] = $group;
        }
    }

    /**
     * Walk the parent chain of a category, returning ancestor ids in
     * root → leaf order. Stops at the first non-category parent (the tree
     * mixes kinds via `parent_id` for variants).
     *
     * @return list<Uuid>
     */
    private function collectCategoryAncestorIds(CatalogObject $category, bool $includeSelf = false): array
    {
        $chain = [];
        $cursor = $includeSelf ? $category : $category->getParent();
        $depthGuard = 0;
        while (null !== $cursor && ObjectKind::Category === $cursor->getKind()) {
            $chain[] = $cursor->getId();
            $cursor = $cursor->getParent();
            if (++$depthGuard > 64) {
                break; // Defensive — ltree depth in practice <10.
            }
        }

        return array_reverse($chain);
    }

    /**
     * VIEW-04 (#408) — annotates each resolved AttributeGroup with the
     * source that contributed it (ObjectType-global, declared on the
     * preview anchor itself, or inherited from a specific ancestor).
     *
     * This is a thin reporting wrapper around the same layered walk
     * {@see resolveForCategoryPreview} performs; it is split out so the
     * categories detail endpoint (#UI-08.14 + VIEW-04) can render
     * "↪ {ancestorName}" badges and the form-schema handler can keep
     * its lightweight `list<AttributeGroup>` shape unchanged.
     *
     * The returned shape is keyed by AttributeGroup UUID. Each entry's
     * `source` is one of:
     *   - `'object_type'`      → globally declared on the ObjectType
     *   - `'declared_here'`    → declared directly on the preview anchor
     *   - `'inherited_from'`   → declared on a strict ancestor
     * For `inherited_from`, `sourceCategory` carries the ancestor's
     * id + path + display name (the {@see CatalogObject} itself is not
     * exposed to keep the shape persistence-free).
     *
     * @return array<string, array{source: 'object_type'|'declared_here'|'inherited_from', sourceCategory: ?array{id: string, code: string, path: ?string}}>
     */
    public function buildSourceMap(ObjectType $type, ?CatalogObject $categoryAnchor): array
    {
        $sources = [];

        foreach ($this->loadObjectTypeGroups($type) as $key => $_group) {
            $sources[$key] = ['source' => 'object_type', 'sourceCategory' => null];
        }

        if (null === $categoryAnchor || ObjectKind::Category !== $categoryAnchor->getKind()) {
            return $sources;
        }

        // Build a per-ancestor list ordered root → leaf (anchor last).
        // We need each ancestor as the *full* CatalogObject to render
        // its display name in the badge — the existing helper returns
        // ids only, so we walk the parent chain again here keeping the
        // entities. The chain is short in practice (<10) so the cost
        // is negligible.
        $ancestorChain = [];
        $cursor = $categoryAnchor;
        $depthGuard = 0;
        while (null !== $cursor && ObjectKind::Category === $cursor->getKind()) {
            array_unshift($ancestorChain, $cursor);
            $cursor = $cursor->getParent();
            if (++$depthGuard > 64) {
                break;
            }
        }

        foreach ($ancestorChain as $ancestor) {
            foreach ($this->groupQuery->findCategoryGroups([$ancestor->getId()], $type) as $junction) {
                $group = $junction->getAttributeGroup();
                $key = $group->getId()->toRfc4122();
                if (isset($sources[$key])) {
                    // ObjectType-global wins on conflict — match the dedup
                    // policy in {@see mergeCategoryGroups}. Same for an
                    // inherited group declared on a deeper ancestor: the
                    // first occurrence (root → leaf) keeps its source.
                    continue;
                }
                $isAnchor = $ancestor === $categoryAnchor;
                $sources[$key] = [
                    'source' => $isAnchor ? 'declared_here' : 'inherited_from',
                    'sourceCategory' => $isAnchor ? null : [
                        'id' => $ancestor->getId()->toRfc4122(),
                        'code' => $ancestor->getCode(),
                        'path' => $ancestor->getPath(),
                    ],
                ];
            }
        }

        return $sources;
    }

    /**
     * MODR-01 (#923) — returns the `display_mode` for each AttributeGroup
     * as declared on the `object_type_attribute_groups` junction for the
     * given ObjectType. Groups not present on the junction (e.g. inherited
     * from a category) fall back to the default `tab` mode at the caller.
     *
     * @return array<string, string> keyed by group UUID, value is 'tab' or 'stacked'
     */
    public function loadObjectTypeGroupDisplayModes(ObjectType $type): array
    {
        $modes = [];
        foreach ($this->groupQuery->findObjectTypeGroups($type) as $junction) {
            $modes[$junction->getAttributeGroup()->getId()->toRfc4122()] = $junction->getDisplayMode();
        }

        return $modes;
    }

    /**
     * Eager-load the AttributeGroupAttribute junctions for a list of
     * groups in one query. Keeps the form-schema endpoint to two round
     * trips (groups + attributes) regardless of the number of groups.
     *
     * @param list<AttributeGroup> $groups
     *
     * @return array<string, list<AttributeGroupAttribute>> keyed by group UUID
     */
    public function loadGroupAttributes(array $groups): array
    {
        if ([] === $groups) {
            return [];
        }

        $byGroup = [];
        foreach ($this->groupQuery->findGroupAttributes($groups) as $junction) {
            $byGroup[$junction->getAttributeGroup()->getId()->toRfc4122()][] = $junction;
        }

        return $byGroup;
    }
}
