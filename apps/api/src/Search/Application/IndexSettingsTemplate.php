<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;

/**
 * Universal Meilisearch index settings (ULV-02 / #983).
 *
 * One consolidated `objects` index replaces the pre-ULV per-kind layout
 * (`products`, `categories`, `assets`, `brands`). Each document carries
 * `tenantId` + `objectTypeId` as filterable facets so the read side can
 * scope by ObjectType the same way it scopes by tenant. Custom kinds get
 * indexed too — previously skipped in MVP per ADR-009, ULV-02 unlocks
 * them because the universal list view treats every ObjectType uniformly.
 *
 * `filterableAttributes` is the union of every pre-ULV kind's filterables
 * plus the dynamic attribute codes (`attributes.is_filterable=true`) the
 * `AttributeFilterableProvisionListener` syncs whenever the operator flips
 * the toggle in Settings → Attributes.
 *
 * `indexName()` keeps its signature so the 12 existing call sites compile
 * unchanged; the `ObjectKind` argument is now an unused legacy hint kept
 * to preserve the public API across the cutover.
 */
final class IndexSettingsTemplate
{
    public const string INDEX_NAME = 'objects';

    /**
     * The document field Meilisearch uses as the primary key. MUST be passed to
     * every addDocuments() call: a Meili index auto-created by a document push
     * (rather than the provisioner) infers its key from the payload, and our
     * documents carry several `*Id` fields, so inference fails ("multiple fields
     * ending with id") and EVERY add silently lands in the failed-task queue —
     * the index stays at 0 docs and search returns nothing. Passing it pins the
     * key whether the index was provisioned first or not.
     */
    public const string PRIMARY_KEY = 'id';

    /**
     * Reserved filterable fields the universal index always carries
     * regardless of operator-defined attributes. `tenantId` enforces the
     * multi-tenant isolation filter; `objectTypeId` scopes the universal
     * list to a specific ObjectType (ULV-03). `kind` stays so the legacy
     * variant-toggle / kind-aware UI surfaces keep working. `category`
     * is denormalized by the indexer from `object_categories` for the
     * `is_categorizable` filter sidebar; `parentId` powers the variant
     * tree view; `mime_type` filters assets; `status`/`enabled` drive
     * status pills; `completeness_pct` powers the Red preset.
     *
     * @var list<string>
     */
    private const array RESERVED_FILTERABLE = [
        'tenantId', 'objectTypeId', 'kind',
        'status', 'enabled',
        'completeness_pct', 'sync_status_aggregate',
        'category', 'parentId', 'mime_type',
        // #2237 — the natural key (`code`, aliased `sku` in the DSL) is the
        // primary way an agent or user grounds a single product ("edit
        // SKU X"). Exact-match filtering on it needs it filterable in Meili;
        // FilterDslResolver maps `sku -> code` so both spellings resolve here.
        'code',
        // #2673 — the ScopedFilterPrefilter hands scoped filter results to
        // Meili as `id IN [...]`; the primary key must be filterable for
        // that expression to parse.
        'id',
    ];

    /**
     * Union of pre-ULV searchable attributes across the four built-in
     * kinds. Custom kinds inherit this baseline; per-attribute searchable
     * extensions (if introduced later) ride on `is_searchable` like the
     * filterable toggle.
     *
     * @var list<string>
     */
    private const array UNIVERSAL_SEARCHABLE = [
        'code', 'name', 'sku', 'brand', 'description',
        'path',
    ];

    /**
     * Union of pre-ULV sortable attributes. `price` lands here so the
     * legacy product-list sort options still resolve.
     *
     * @var list<string>
     */
    private const array UNIVERSAL_SORTABLE = [
        'createdAt', 'updatedAt', 'name', 'price',
    ];

    public function __construct(
        private readonly ?AttributeRepositoryInterface $attributes = null,
    ) {
    }

    /**
     * Always returns the universal `objects` index name. The `?ObjectKind`
     * argument is a legacy hint kept to preserve every pre-ULV call site
     * without a churn-only rename.
     */
    public static function indexName(?ObjectKind $kind = null): string
    {
        return self::INDEX_NAME;
    }

    /**
     * @return array<string, mixed> settings payload accepted by
     *                              `Meilisearch\Endpoints\Indexes::updateSettings`
     */
    public function settingsFor(?ObjectKind $kind = null): array
    {
        return [
            'searchableAttributes' => self::UNIVERSAL_SEARCHABLE,
            'filterableAttributes' => $this->filterableAttributes(),
            'sortableAttributes' => self::UNIVERSAL_SORTABLE,
            'displayedAttributes' => ['*'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
            // Meili's default `maxTotalHits` (1000) caps cross-page
            // selection at the first page and silently truncates the
            // result. Raised to match the bulk selection HARD_CAP
            // (10 000) — `BulkSelectionController` already enforces that
            // as the absolute ceiling.
            'pagination' => ['maxTotalHits' => 10_000],
        ];
    }

    /**
     * @return list<string>
     */
    private function filterableAttributes(): array
    {
        $dynamic = null === $this->attributes ? [] : $this->attributes->filterableCodes();
        $merged = array_values(array_unique([...self::RESERVED_FILTERABLE, ...$dynamic]));
        sort($merged);

        return $merged;
    }

    /**
     * Every ObjectKind is indexed under the universal `objects` index.
     * Custom kinds were previously skipped per ADR-009 (MVP scope) — ULV
     * unlocks them because the universal list treats every ObjectType
     * uniformly. Used by `MeilisearchIndexProvisioner` + reindex CLI.
     *
     * @return list<ObjectKind>
     */
    public static function indexedKinds(): array
    {
        return [
            ObjectKind::Product,
            ObjectKind::Category,
            ObjectKind::Asset,
            ObjectKind::Custom,
        ];
    }
}
