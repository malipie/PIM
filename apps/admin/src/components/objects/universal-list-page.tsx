/*
 * UP-06 (#1024) — universal list page parametrized by `objectTypeId`.
 *
 * This is the extraction of `apps/admin/src/features/catalog/products/list.tsx`
 * (the operator-facing /products view) into a parametrized component
 * that serves BOTH `/products` (built-in product ObjectType) AND
 * `/objects/:slug` (any ObjectType — product / category / asset / brand /
 * custom). Pixel-perfect parity with the legacy /products page is the
 * acceptance criterion.
 *
 * Differences vs. the legacy ProductListPage:
 *   - `useCatalogSearch` accepts `objectTypeId` so the consolidated
 *     `/api/search/objects?objectTypeId=` route (UP-06 BE) handles
 *     non-built-in kinds; built-in kinds still use the per-kind sugar
 *     route via the `searchKind` prop for stable RBAC + facet whitelist
 *     semantics.
 *   - PATCH / variant fetch / select-all-matching paths swap to the
 *     poly-kind `/api/objects/*` routes shipped in UP-01..05.
 *   - localStorage keys are scoped per-ObjectType: `pim.objectList.<id>.*`
 *     instead of the legacy `pim.products.*` keys.
 *   - Capability-gated features (variants toggle, bulk category modal,
 *     bulk publish modal) are conditionally rendered based on
 *     `hasVariants` / `isCategorizable` from the list-schema response.
 *   - Create CTA navigates to `createPath` (parent supplies — for built-in
 *     product it's `/products/new`, for custom it's `/objects/:slug/new`
 *     wired by UP-08).
 *
 * UP-10 wired this component into `/products`; the legacy
 * ProductListPage was retired in NUI-05 (#1424) once the
 * dual-maintenance window closed, so this is the only list
 * implementation for every ObjectType.
 */
import { useQuery } from '@tanstack/react-query';
import { Plus, Search } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';

import { BulkBar } from '@/components/catalog/bulk-bar';
import { ColumnManager } from '@/components/catalog/column-manager';
import { DeletePresetDialog } from '@/components/catalog/delete-preset-dialog';
import { ExcelLikeGrid } from '@/components/catalog/excel-like-grid';
import { FilterChipsBar } from '@/components/catalog/filter-chips-bar';
import {
  PAGE_SIZE_OPTIONS,
  type PageSize,
  PaginationBar,
} from '@/components/catalog/pagination-bar';
import { ProductsGrid, type ProductsGridRow } from '@/components/catalog/products-grid';
import { type RollbackSession, RollbackToast } from '@/components/catalog/rollback-toast';
import { SaveAsSmartPresetModal } from '@/components/catalog/save-as-smart-preset-modal';
import { SelectionToolbar } from '@/components/catalog/selection-toolbar';
import { SmartFilterPresetsRow } from '@/components/catalog/smart-filter-presets-row';
import type { SyncAggregate } from '@/components/catalog/sync-aggregate-icon';
import { type VariantsMode, VariantsToggle } from '@/components/catalog/variants-toggle';
import { type ProductsViewMode, ViewModeToggle } from '@/components/catalog/view-mode-toggle';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import {
  type CatalogSearchHit,
  useCatalogSearch,
} from '@/features/catalog/search/use-catalog-search';
import { useListSchema } from '@/hooks/use-list-schema';
import { usePageActions } from '@/layout/page-actions-context';
import { unwrapAttributesIndexed } from '@/lib/attributes-indexed';
import { dslToFlatConditions, type FilterDsl } from '@/lib/filters/filter-dsl';
import { readInitialFilterDsl } from '@/lib/filters/list-url-seed';
import { dslToBase64 } from '@/lib/filters/url-serializer';
import { useFilterDslState } from '@/lib/filters/use-filter-dsl-state';
import { type SmartFilterPreset, useSmartPresets } from '@/lib/filters/use-smart-presets';
import { type ExcelObjectRow, toExcelColumns, toExcelRow } from '@/lib/grid/excel-columns';
import { useAttributeOptionLabels } from '@/lib/grid/grid-attribute-cell';
import { clampColumnWidth, overridesFromColumns, setColumnWidth } from '@/lib/grid/overrides';
import type { GridColumn, GridColumnOverride, ViewColumnSeed } from '@/lib/grid/types';
import { useGridColumns } from '@/lib/grid/use-grid-columns';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

const CmdKPalette = lazy(() =>
  import('@/components/agent/cmd-k-palette').then((m) => ({ default: m.CmdKPalette })),
);
const AdvancedFilterPanel = lazy(() =>
  import('@/components/catalog/advanced-filter-panel').then((m) => ({
    default: m.AdvancedFilterPanel,
  })),
);
const BulkCategoryModal = lazy(() =>
  import('@/components/catalog/bulk-actions/category-modal').then((m) => ({
    default: m.BulkCategoryModal,
  })),
);
const BulkDuplicateModal = lazy(() =>
  import('@/components/catalog/bulk-actions/duplicate-modal').then((m) => ({
    default: m.BulkDuplicateModal,
  })),
);
const BulkGenerateContentModal = lazy(() =>
  import('@/components/catalog/bulk-actions/generate-content-modal').then((m) => ({
    default: m.BulkGenerateContentModal,
  })),
);
const BulkChangeStatusDialog = lazy(() =>
  import('@/components/catalog/bulk-change-status-dialog').then((m) => ({
    default: m.BulkChangeStatusDialog,
  })),
);
const BulkDeleteConfirmModal = lazy(() =>
  import('@/components/catalog/bulk-actions/hard-confirm-modal').then((m) => ({
    default: m.BulkDeleteConfirmModal,
  })),
);
const BulkWizard = lazy(() =>
  import('@/components/catalog/bulk-wizard/bulk-wizard').then((m) => ({ default: m.BulkWizard })),
);

interface CatalogObjectListEntry {
  id: string;
  code: string;
  enabled?: boolean;
  status?: string;
  createdAt?: string;
  updatedAt?: string;
  attributesIndexed?: Record<string, unknown>;
  completenessPct?: number;
  syncStatusAggregate?: string;
  parent?: { id?: string } | null;
  parentId?: string | null;
}

interface ListResponse {
  totalItems?: number;
  member?: CatalogObjectListEntry[];
  'hydra:member'?: CatalogObjectListEntry[];
  'hydra:totalItems'?: number;
}

const DEFAULT_FACETS = ['status'];
const PRODUCT_FACETS = ['status', 'brand'];

/**
 * GRID-P1-03 — visual parity with the pre-GRID list: the schema always
 * emits `status`/`updatedAt` system columns, but the legacy grid never
 * showed them. Hidden by default until the user opts in (column manager,
 * GRID-P2-01); stored prefs override this.
 */
/**
 * GRID-P1-03 / #2401 follow-up — schema always emits `status`/`updatedAt`
 * (legacy grid hid them). For products we also surface the real, editable
 * `name` and `price` attribute columns by default (they are `show_in_list`
 * =false so they start hidden) INSTEAD of the read-only `__name`/`__price`
 * view-seeds, so the default Nazwa/Cena columns are inline-editable.
 */
function defaultColumnOverrides(hasName: boolean, hasPrice: boolean): GridColumnOverride[] {
  return [
    { key: 'code' },
    ...(hasName ? [{ key: 'name', hidden: false }] : []),
    ...(hasPrice ? [{ key: 'price', hidden: false }] : []),
    { key: 'status', hidden: true },
    { key: 'updatedAt', hidden: true },
  ];
}

export interface UniversalListPageProps {
  /** ObjectType UUID — drives schema fetch, search scope, and storage keys. */
  objectTypeId: string;
  /** ObjectType code (e.g. `product`, `samochody`) — drives the slug-based create link for custom kinds. */
  objectTypeCode: string;
  /** Localised label for the header. */
  objectTypeLabel: string;
  /**
   * Built-in search route key. When provided, `/api/search/{kind}` is used
   * (preserves the per-kind facet whitelist + RBAC code). When undefined,
   * the universal `/api/search/objects?objectTypeId=` route handles the
   * query — required for custom kinds.
   */
  searchKind?: 'products' | 'categories' | 'assets';
  /** Capability flag from the list-schema response. */
  hasVariants: boolean;
  /** Capability flag from the list-schema response. */
  isCategorizable: boolean;
  /** Capability flag from the list-schema response — hides the thumbnail slot when false. */
  hasMultimedia?: boolean;
  /** Where the Create CTA / empty-state CTA navigates. */
  createPath: string;
  /** Builder for the detail-page route per row. */
  detailPathFor: (id: string) => string;
}

function readInitialPageSize(objectTypeId: string): PageSize {
  if (typeof window === 'undefined') return 50;
  const urlParam = new URLSearchParams(window.location.search).get('pageSize');
  const parsedUrl = urlParam !== null ? Number(urlParam) : Number.NaN;
  if (PAGE_SIZE_OPTIONS.includes(parsedUrl as PageSize)) {
    return parsedUrl as PageSize;
  }
  const stored = window.localStorage.getItem(`pim.objectList.${objectTypeId}.pageSize`);
  const parsedStored = stored !== null ? Number(stored) : Number.NaN;
  if (PAGE_SIZE_OPTIONS.includes(parsedStored as PageSize)) {
    return parsedStored as PageSize;
  }
  return 50;
}

function readInitialPage(): number {
  if (typeof window === 'undefined') return 1;
  const urlParam = new URLSearchParams(window.location.search).get('page');
  const parsed = urlParam !== null ? Number(urlParam) : Number.NaN;
  return Number.isFinite(parsed) && parsed >= 1 ? parsed : 1;
}

export function UniversalListPage({
  objectTypeId,
  objectTypeCode,
  objectTypeLabel,
  searchKind,
  hasVariants,
  isCategorizable,
  hasMultimedia = true,
  createPath,
  detailPathFor,
}: UniversalListPageProps) {
  const { t, i18n } = useTranslation();
  const uiLocale = i18n.language.split('-')[0] ?? i18n.language;
  const isProduct = searchKind === 'products';
  const isCustomKind = searchKind === undefined;
  const exportNavigate = useNavigate();

  // EXR-14 — context entries into the export wizard (D5: full page, not a
  // modal). selectedIds/filter DSL travel via router state, never the URL.
  const goToExport = (scope: 'selected' | 'filter', ids?: string[]) => {
    void exportNavigate(`/integrations/exports/new?scope=${scope}`, {
      state: {
        entityType: isProduct ? 'product' : 'custom_module',
        objectTypeId: isProduct ? null : objectTypeId,
        selectedIds: scope === 'selected' ? (ids ?? []) : null,
        filterDsl: scope === 'filter' ? panelDsl : null,
      },
    });
  };
  const facets = isProduct ? PRODUCT_FACETS : DEFAULT_FACETS;

  // GRID-P1-03/04 — the single column model for both views. View-owned
  // derived columns (name fallback, categories, sync, price, variant
  // axis) fill the gaps the list-schema cannot know about; seeds whose
  // key already exists as a schema column are skipped by the resolver.
  const schemaQuery = useListSchema(objectTypeId, { full: true });
  const gridViewColumns = useMemo<ViewColumnSeed[]>(() => {
    const schemaColumns = schemaQuery.data?.columns ?? [];
    const has = (key: string): boolean => schemaColumns.some((column) => column.key === key);
    const seeds: ViewColumnSeed[] = [
      { key: '__name', type: 'view_name', label: { pl: 'Nazwa', en: 'Name' }, after: 'code' },
    ];
    if (isCategorizable) {
      seeds.push({
        key: '__categories',
        type: 'view_categories',
        label: { pl: 'Kategorie', en: 'Categories' },
        after: has('name') ? 'name' : '__name',
      });
    }
    if (isProduct) {
      seeds.push({
        key: '__sync',
        type: 'view_sync',
        label: { pl: 'Kanały', en: 'Channels' },
        after: 'completeness',
      });
      if (!has('price')) {
        seeds.push({
          key: '__price',
          type: 'view_price',
          label: { pl: 'Cena', en: 'Price' },
          after: '__sync',
        });
      }
    }
    if (hasVariants) {
      seeds.push({
        key: '__variant',
        type: 'view_variant',
        label: { pl: 'Wariant', en: 'Variant' },
      });
    }
    // '__name' seed is skipped by the resolver when the schema exposes a
    // `name` attribute column — the seed list stays static either way.
    return has('name') ? seeds.filter((seed) => seed.key !== '__name') : seeds;
  }, [schemaQuery.data, isCategorizable, isProduct, hasVariants]);

  const {
    columns: modelColumns,
    setOverrides,
    resetOverrides,
  } = useGridColumns(objectTypeId, {
    viewColumns: gridViewColumns,
    defaultOverrides: defaultColumnOverrides(
      (schemaQuery.data?.columns ?? []).some((c) => c.key === 'name'),
      isProduct && (schemaQuery.data?.columns ?? []).some((c) => c.key === 'price'),
    ),
    // GRID-P3-02 — resolve against the full catalogue so any attached
    // attribute can be revealed as a column by the manager.
    fullSchema: true,
  });
  // Visual parity: the schema labels the identifier column generically
  // ("Identyfikator"), but the products list has always shown "SKU".
  const allColumns = useMemo(
    () =>
      isProduct
        ? modelColumns.map((column) =>
            column.key === 'code' ? { ...column, label: { pl: 'SKU', en: 'SKU' } } : column,
          )
        : modelColumns,
    [modelColumns, isProduct],
  );
  const visibleColumns = useMemo(() => allColumns.filter((c) => !c.hidden), [allColumns]);
  // PTR-01 — the Komfort/Kompakt density toggle was removed; rows render at
  // the default comfortable density everywhere.
  const density = 'normal' as const;

  // PTR-01 — the "Dodaj" CTA moved from the toolbar into the global topbar
  // action slot (same pattern as the catalogs/exports hubs), styled as the
  // brand orange CTA.
  usePageActions(
    useMemo(
      () => (
        <Link
          to={createPath}
          className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover"
        >
          <Plus className="size-4" aria-hidden />
          {t('products.toolbar.add', { defaultValue: 'Dodaj' })}
        </Link>
      ),
      [t, createPath],
    ),
  );

  // GRID-P5-03 (#2399) - one sort state for both views. Attribute
  // columns map to ?order[attribute.{code}] (AttributeOrderFilter,
  // ADR-0028); system columns to their core fields via OrderById.
  // Sorted requests ride LIMIT/OFFSET (page), never the id-cursor.
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(() => {
    if (typeof window === 'undefined') return null;
    const raw = new URLSearchParams(window.location.search).get('sort');
    if (raw === null) return null;
    const [key, dir] = raw.split(':');
    return key !== undefined && (dir === 'asc' || dir === 'desc') ? { key, dir } : null;
  });
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    if (sort === null) params.delete('sort');
    else params.set('sort', `${sort.key}:${sort.dir}`);
    window.history.replaceState(
      null,
      '',
      `${window.location.pathname}?${params.toString()}${window.location.hash}`,
    );
  }, [sort]);
  const handleSortChange = (key: string): void => {
    setPage(1);
    setSort((prev) =>
      prev?.key !== key ? { key, dir: 'asc' } : prev.dir === 'asc' ? { key, dir: 'desc' } : null,
    );
  };

  // GRID-P2-01/02 — manager mutations + header drag-resize persist as
  // quick-pref overrides (SavedView round-trip lands in M4).
  const applyColumns = (next: GridColumn[]): void => setOverrides(overridesFromColumns(next));
  const handleColumnResize = (key: string, width: number): void => {
    applyColumns(setColumnWidth(allColumns, key, clampColumnWidth(width)));
  };
  const optionLabels = useAttributeOptionLabels(visibleColumns);
  const excelColumns = useMemo(
    () => toExcelColumns(visibleColumns, uiLocale, optionLabels),
    [visibleColumns, uiLocale, optionLabels],
  );

  const [query, setQuery] = useState('');
  // PTR-01 — `filters` used to be repopulated by the removed saved-view apply;
  // it stays as the (empty) base for the search request map.
  const [filters] = useState<Record<string, string | string[]>>({});
  const [page, setPage] = useState<number>(() => readInitialPage());
  const [pageSize, setPageSize] = useState<PageSize>(() => readInitialPageSize(objectTypeId));

  useEffect(() => {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem(`pim.objectList.${objectTypeId}.pageSize`, String(pageSize));
  }, [pageSize, objectTypeId]);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    params.set('page', String(page));
    params.set('pageSize', String(pageSize));
    const url = `${window.location.pathname}?${params.toString()}${window.location.hash}`;
    window.history.replaceState(null, '', url);
  }, [page, pageSize]);

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [showSelectedOnly, setShowSelectedOnly] = useState(false);
  const [crossPageSelection, setCrossPageSelection] = useState<{
    active: boolean;
    totalMatched: number;
    capped: boolean;
  }>({ active: false, totalMatched: 0, capped: false });
  const [crossPageLoading, setCrossPageLoading] = useState(false);
  const [bulkWizardOpen, setBulkWizardOpen] = useState(false);
  const [bulkCategoryOpen, setBulkCategoryOpen] = useState(false);
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  const [bulkDuplicateOpen, setBulkDuplicateOpen] = useState(false);
  // AICG-P5-03 (#2341) — bulk "Generuj treść AI" modal.
  const [bulkGenerateOpen, setBulkGenerateOpen] = useState(false);
  // WFL-P3-04 / #2493 — bulk workflow-transition dialog.
  const [bulkStatusOpen, setBulkStatusOpen] = useState(false);
  const [cmdKOpen, setCmdKOpen] = useState(false);
  const [lastBulkSession, setLastBulkSession] = useState<RollbackSession | null>(null);

  useEffect(() => {
    const handler = (event: KeyboardEvent): void => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setCmdKOpen((prev) => !prev);
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  const [variantsMode, setVariantsMode] = useState<VariantsMode>('tree');
  const [viewMode, setViewMode] = useState<ProductsViewMode>(() => {
    if (typeof window === 'undefined') return 'grid';
    const stored = window.localStorage.getItem(`pim.objectList.${objectTypeId}.viewMode`);
    return stored === 'excel' ? 'excel' : 'grid';
  });
  const handleViewModeChange = (next: ProductsViewMode): void => {
    setViewMode(next);
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(`pim.objectList.${objectTypeId}.viewMode`, next);
    }
  };

  const [expandedMasters, setExpandedMasters] = useState<Set<string>>(new Set());
  const {
    presets: smartPresets,
    isLoading: smartPresetsLoading,
    create: createSmartPreset,
    remove: removeSmartPreset,
  } = useSmartPresets({ withCounts: true, resource: objectTypeCode });
  const [activeSmartPresetId, setActiveSmartPresetId] = useState<string | null>(null);
  const [presetToDelete, setPresetToDelete] = useState<SmartFilterPreset | null>(null);
  // DASH-01 (#2249) — dashboard drill-downs deep-link into this list with
  // `filter[attr][op]=…` params; seed the panel state once from the URL.
  const [initialUrlDsl] = useState<FilterDsl | null>(() =>
    typeof window === 'undefined' ? null : readInitialFilterDsl(window.location.search),
  );
  const [advancedPanelOpen, setAdvancedPanelOpen] = useState(initialUrlDsl !== null);
  // EXR-10 — shared filter-DSL state (same hook the export wizard uses).
  const {
    conditions: panelConditions,
    setConditions: setPanelConditions,
    matchOperator,
    setMatchOperator,
    scope: panelScope,
    setScope: setPanelScope,
    dsl: panelDsl,
  } = useFilterDslState(initialUrlDsl);
  const [showSaveAsPresetModal, setShowSaveAsPresetModal] = useState(false);

  const { searchFilters, rangeFilters } = useMemo(() => {
    const sf: Record<string, string | string[]> = { ...filters };
    const rf: Record<string, { gte?: number; lte?: number }> = {};
    return { searchFilters: sf, rangeFilters: rf };
  }, [filters]);

  const isSearchActive =
    query !== '' ||
    Object.keys(searchFilters).length > 0 ||
    Object.keys(rangeFilters).length > 0 ||
    activeSmartPresetId !== null ||
    panelConditions.length > 0;

  const activePreset = activeSmartPresetId
    ? smartPresets.find((p) => p.id === activeSmartPresetId)
    : undefined;
  const filterBlob = useMemo<string | undefined>(() => {
    if (activePreset !== undefined) return undefined;
    if (panelDsl === null) return undefined;
    try {
      return dslToBase64(panelDsl);
    } catch {
      return undefined;
    }
  }, [activePreset, panelDsl]);

  // biome-ignore lint/correctness/useExhaustiveDependencies: intentional — `setPage` is stable
  useEffect(() => {
    setPage(1);
  }, [query, filters, activeSmartPresetId, filterBlob, variantsMode]);

  const searchTarget = searchKind
    ? { kind: searchKind as 'products' | 'categories' | 'assets' }
    : { objectTypeId };
  const { result: searchResult, isLoading: isSearchLoading } = useCatalogSearch({
    ...searchTarget,
    query,
    filters: searchFilters,
    rangeFilters,
    smartPresetId: activePreset?.slug ?? activePreset?.id,
    filterBlob,
    facets,
    page,
    perPage: pageSize,
  });

  // UP-06 — non-search browse path uses /api/objects?objectType= so every
  // ObjectType (built-in + custom) lands on the same poly-kind endpoint.
  // Variants mode (`tree`) hides variants by filtering `parentId IS NULL`
  // server-side so a freshly-generated master's children don't fill the
  // page; this is gated by `hasVariants` so non-variant kinds never see
  // the filter (they simply have no `parent_id` data to filter on).
  const listQueryKey = useMemo(
    () =>
      [
        'object-list-browse',
        objectTypeId,
        page,
        pageSize,
        hasVariants ? variantsMode : 'flat',
        sort === null ? 'nosort' : `${sort.key}:${sort.dir}`,
      ] as const,
    [objectTypeId, page, pageSize, hasVariants, variantsMode, sort],
  );
  const listQuery = useQuery({
    queryKey: listQueryKey,
    enabled: !isSearchActive,
    staleTime: 30 * 1000,
    queryFn: async (): Promise<ListResponse> => {
      const params: Record<string, string | number> = {
        objectType: objectTypeId,
        itemsPerPage: pageSize,
        page,
      };
      if (hasVariants && variantsMode === 'tree') {
        params.parent_id = 'null';
      }
      if (sort !== null) {
        const systemOrderField: Record<string, string> = {
          code: 'code',
          status: 'status',
          completeness: 'completenessPct',
          updatedAt: 'updatedAt',
        };
        const column = visibleColumns.find((c) => c.key === sort.key);
        const orderProperty =
          column?.source === 'attribute'
            ? `attribute.${sort.key}`
            : (systemOrderField[sort.key] ?? null);
        if (orderProperty !== null) {
          params[`order[${orderProperty}]`] = sort.dir;
        }
      }
      return jsonFetch<ListResponse>('/api/objects', {
        accept: 'application/ld+json',
        query: params,
      });
    },
  });
  const refetch = (): void => {
    void listQuery.refetch();
  };
  const products = listQuery.data?.member ?? listQuery.data?.['hydra:member'] ?? [];
  const totalForList =
    listQuery.data?.totalItems ?? listQuery.data?.['hydra:totalItems'] ?? products.length;
  const isListLoading = listQuery.isLoading;

  const [variantsByMasterId, setVariantsByMasterId] = useState<Record<string, ProductsGridRow[]>>(
    {},
  );

  const fetchVariantsForMaster = async (masterId: string): Promise<void> => {
    if (variantsByMasterId[masterId] !== undefined) return;
    try {
      const body = await jsonFetch<{
        member?: CatalogObjectListEntry[];
        'hydra:member'?: CatalogObjectListEntry[];
      }>(`/api/objects?parent_id=${masterId}&itemsPerPage=200&objectType=${objectTypeId}`);
      const list = body.member ?? body['hydra:member'] ?? [];
      setVariantsByMasterId((prev) => ({
        ...prev,
        [masterId]: list.map(catalogObjectToRow),
      }));
    } catch (err) {
      toast.error(
        httpErrorDetail(err) ??
          t('products.list.action_failed', { defaultValue: 'Operacja nie powiodła się.' }),
      );
    }
  };

  const rawBaseRows = useMemo<ProductsGridRow[]>(() => {
    if (isSearchActive) {
      return (searchResult?.hits ?? []).map(searchHitToRow);
    }
    return products.map(catalogObjectToRow);
  }, [isSearchActive, products, searchResult]);

  // #2319 — Excel-edit optimistic overlay. Without it a committed cell flashes
  // the pre-edit value for ~0.5 s: onCommit fires, the grid stops editing and
  // re-reads the (still stale) row until the async refetch lands. The overlay
  // keeps the just-committed value visible; each entry self-clears once the
  // refetched row carries it (or on a failed commit that never reconciles the
  // parent restores by re-issuing the edit).
  const [optimisticEdits, setOptimisticEdits] = useState<Map<string, Partial<ProductsGridRow>>>(
    () => new Map(),
  );

  useEffect(() => {
    setOptimisticEdits((prev) => {
      if (prev.size === 0) return prev;
      const next = new Map(prev);
      for (const [id, edit] of prev) {
        const row = rawBaseRows.find((r) => r.id === id);
        if (
          row &&
          Object.entries(edit).every(([key, value]) => row[key as keyof ProductsGridRow] === value)
        ) {
          next.delete(id);
        }
      }
      return next.size === prev.size ? prev : next;
    });
  }, [rawBaseRows]);

  const baseRows = useMemo<ProductsGridRow[]>(() => {
    if (optimisticEdits.size === 0) return rawBaseRows;
    return rawBaseRows.map((row) => {
      const edit = optimisticEdits.get(row.id);
      return edit ? { ...row, ...edit } : row;
    });
  }, [rawBaseRows, optimisticEdits]);

  const filteredRows = useMemo<ProductsGridRow[]>(() => {
    if (showSelectedOnly && selected.size > 0) {
      return baseRows.filter((row) => selected.has(row.id));
    }
    return baseRows;
  }, [baseRows, showSelectedOnly, selected]);

  const variantsByMasterCount = useMemo(() => {
    const counts = new Map<string, number>();
    for (const row of filteredRows) {
      if (row.parentId === null) continue;
      counts.set(row.parentId, (counts.get(row.parentId) ?? 0) + 1);
    }
    return counts;
  }, [filteredRows]);

  const visible = useMemo<ProductsGridRow[]>(() => {
    if (!hasVariants || variantsMode === 'flat') return filteredRows;
    const out: ProductsGridRow[] = [];
    for (const row of filteredRows) {
      if (row.parentId !== null) continue;
      out.push(row);
      if (expandedMasters.has(row.id)) {
        out.push(...(variantsByMasterId[row.id] ?? []));
      }
    }
    return out;
  }, [filteredRows, hasVariants, variantsMode, expandedMasters, variantsByMasterId]);

  const toggleExpand = (masterId: string): void => {
    setExpandedMasters((prev) => {
      const next = new Set(prev);
      if (next.has(masterId)) next.delete(masterId);
      else next.add(masterId);
      return next;
    });
    if (hasVariants && variantsMode === 'tree' && !expandedMasters.has(masterId)) {
      void fetchVariantsForMaster(masterId);
    }
  };

  const isLoading = isSearchActive ? isSearchLoading : isListLoading;

  const totalHits = isSearchActive ? (searchResult?.totalHits ?? 0) : totalForList;
  // #2673 — the scoped-filter prefilter capped at 10k ids; hits are approximate.
  const scopeTruncated = isSearchActive && searchResult?.scopeTruncated === true;

  const toggleSelect = (id: string): void => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleSelectAll = (): void => {
    setSelected((prev) => {
      const masters = visible.filter((r) => r.parentId === null);
      const allSelected = masters.every((m) => prev.has(m.id)) && prev.size === masters.length;
      if (allSelected) return new Set();
      return new Set(masters.map((m) => m.id));
    });
  };

  const handleApplySmartPreset = (preset: SmartFilterPreset | null): void => {
    if (preset === null) {
      setActiveSmartPresetId(null);
      setPanelConditions([]);
      return;
    }

    setActiveSmartPresetId(preset.id);
    // PTR-01 — a preset now also carries a column layout (the merged "saved
    // view" role). It stores only the columns that were on the list; restore
    // exactly that set — the saved columns in their order, then every other
    // schema column hidden — so applying a preset shows precisely what was
    // visible when it was saved, regardless of each column's default. null =
    // legacy filter-only preset → leave the current columns untouched. Keys
    // absent from the schema are dropped by the resolver (stale attribute).
    if (preset.columns != null) {
      const savedKeys = new Set(preset.columns.map((c) => c.key));
      const hiddenRest = allColumns
        .filter((c) => !savedKeys.has(c.key))
        .map((c) => ({ key: c.key, hidden: true }));
      setOverrides([...preset.columns, ...hiddenRest]);
    }
    const conditions = dslToFlatConditions(preset.query);
    if (conditions === null) {
      toast.info(
        t('products.smart_filters.nested_unsupported', {
          defaultValue: 'Preset zawiera zagnieżdżone grupy AND/OR (Query mode w VIEW-09b).',
        }),
      );
      setPanelConditions([]);
      return;
    }

    setPanelConditions(conditions);
  };

  const handleApplyAdvancedPanel = (): void => {
    setAdvancedPanelOpen(false);
    setActiveSmartPresetId(null);
  };

  const handleExcelCommit = async (
    row: ProductsGridRow,
    colKey: string,
    value: unknown,
  ): Promise<void> => {
    // Show the new value instantly (overlay); it self-clears once the refetch
    // lands with the persisted value. On error we drop the overlay so the cell
    // falls back to the server truth.
    setOptimisticEdits((prev) => {
      const next = new Map(prev);
      next.set(row.id, { ...next.get(row.id), [colKey]: value });
      return next;
    });
    // GRID-P6-02 — attribute column edits carry the `attr:{code}` excel
    // key; PATCH the attribute and optimistically overlay the raw
    // envelope so the cell shows the new reading before the refetch.
    const attrCode = colKey.startsWith('attr:') ? colKey.slice('attr:'.length) : null;
    let attrPayload: unknown = value;
    if (attrCode !== null) {
      const column = visibleColumns.find((c) => c.key === attrCode);
      let envelope: Record<string, unknown>;
      if (column?.type === 'select') {
        envelope = { option_code: value };
      } else if (column?.type === 'price') {
        // Price is an {amount, currency} envelope — edit the amount,
        // keep the existing currency (or default PLN).
        const prev = row.attributesIndexed?.[attrCode] as { currency?: unknown } | undefined;
        const currency = typeof prev?.currency === 'string' ? prev.currency : 'PLN';
        const amount = value === null ? null : Number(value);
        envelope = { amount, currency };
        attrPayload = value === null ? null : { amount, currency };
      } else {
        envelope = { value };
      }
      setOptimisticEdits((prev) => {
        const next = new Map(prev);
        const base = next.get(row.id) ?? {};
        next.set(row.id, {
          ...base,
          attributesIndexed: { ...(row.attributesIndexed ?? {}), [attrCode]: envelope },
        });
        return next;
      });
    }
    try {
      if (colKey === 'enabled') {
        await jsonFetch(`/api/objects/${row.id}`, {
          method: 'PATCH',
          body: { enabled: Boolean(value) },
          contentType: 'application/merge-patch+json',
        });
      } else if (colKey === 'name') {
        await jsonFetch(`/api/objects/${row.id}`, {
          method: 'PATCH',
          body: { attributes: { name: value } },
          contentType: 'application/merge-patch+json',
        });
      } else if (attrCode !== null) {
        await jsonFetch(`/api/objects/${row.id}`, {
          method: 'PATCH',
          body: { attributes: { [attrCode]: attrPayload } },
          contentType: 'application/merge-patch+json',
        });
      } else {
        return;
      }
      if (attrCode !== null) {
        // The attributesIndexed overlay is an object, so the value-equality
        // self-clear never fires; await the refetched truth then drop it.
        await listQuery.refetch();
        setOptimisticEdits((prev) => {
          if (!prev.has(row.id)) return prev;
          const next = new Map(prev);
          next.delete(row.id);
          return next;
        });
      } else {
        refetch();
      }
    } catch (err) {
      setOptimisticEdits((prev) => {
        if (!prev.has(row.id)) return prev;
        const next = new Map(prev);
        next.delete(row.id);
        return next;
      });
      toast.error(
        httpErrorDetail(err) ??
          t('products.list.action_failed', { defaultValue: 'Operacja nie powiodła się.' }),
      );
    }
  };

  const onBulkApplied = (): void => {
    setSelected(new Set());
    setShowSelectedOnly(false);
    refetch();
  };

  const showEmptyState = !isLoading && baseRows.length === 0 && !isSearchActive;

  return (
    <div id="universal-list-page" className="space-y-5 pb-24">
      {scopeTruncated && (
        <div
          role="status"
          className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-[12.5px] text-amber-900"
        >
          {t('products.advanced_filter.scope_truncated', {
            defaultValue: 'Wynik przybliżony — zawęź filtr (limit 10 000 obiektów w kontekście).',
          })}
        </div>
      )}

      <SmartFilterPresetsRow
        presets={smartPresets}
        activeId={activeSmartPresetId}
        onSelect={handleApplySmartPreset}
        onCreate={() => {
          // PTR-01 — a preset now snapshots filters + columns, so it can be
          // saved even with nothing filtered (the modal captures the current
          // column layout). No more "add a condition first" guard.
          setShowSaveAsPresetModal(true);
        }}
        onDelete={(preset) => {
          setPresetToDelete(preset);
        }}
        isLoading={smartPresetsLoading}
      />

      <DeletePresetDialog
        preset={presetToDelete}
        onClose={() => {
          setPresetToDelete(null);
        }}
        onDeleted={(presetId) => {
          // Drop the active filter if the deleted preset was applied.
          if (activeSmartPresetId === presetId) handleApplySmartPreset(null);
        }}
        remove={removeSmartPreset}
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-[280px]">
          <Search
            className="absolute left-3.5 top-1/2 -translate-y-1/2 size-4 text-zinc-500"
            aria-hidden="true"
          />
          <input
            type="search"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
            }}
            placeholder={t('products.toolbar.search_placeholder', {
              defaultValue: 'Szukaj po SKU, nazwie, EAN, atrybucie…',
            })}
            aria-label={t('products.toolbar.search_aria', { defaultValue: 'Szukaj produktów' })}
            className="w-full h-11 pl-10 pr-4 rounded-2xl bg-white shadow-sm text-[14px] placeholder:text-zinc-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
          />
        </div>

        <Button
          type="button"
          variant={advancedPanelOpen ? 'default' : 'outline'}
          onClick={() => setAdvancedPanelOpen((prev) => !prev)}
          className="h-11 rounded-2xl"
          aria-expanded={advancedPanelOpen}
          aria-controls="advanced-filter-panel"
        >
          {t('products.toolbar.filter_by_attribute_button', {
            defaultValue: 'Filtruj zaawansowane',
          })}
          {panelConditions.length > 0 && (
            <span className="ml-1.5 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-700">
              {panelConditions.length}
            </span>
          )}
        </Button>

        {(isProduct || isCustomKind) && panelDsl !== null && (
          <Button
            variant="outline"
            onClick={() => goToExport('filter')}
            className="h-11 rounded-2xl"
          >
            {t('products.toolbar.export_filtered', { defaultValue: 'Eksportuj wynik' })}
          </Button>
        )}

        {hasVariants ? <VariantsToggle mode={variantsMode} onChange={setVariantsMode} /> : null}

        <ColumnManager
          columns={allColumns}
          onChange={applyColumns}
          onReset={resetOverrides}
          locale={uiLocale}
        />
        <ViewModeToggle mode={viewMode} onChange={handleViewModeChange} />
      </div>

      <div id="advanced-filter-panel">
        {advancedPanelOpen ? (
          <Suspense fallback={null}>
            <AdvancedFilterPanel
              open={advancedPanelOpen}
              conditions={panelConditions}
              setConditions={setPanelConditions}
              matchOperator={matchOperator}
              setMatchOperator={setMatchOperator}
              onApply={handleApplyAdvancedPanel}
              onClose={() => setAdvancedPanelOpen(false)}
              onClear={() => {
                setPanelConditions([]);
                setPanelScope(null);
                setActiveSmartPresetId(null);
              }}
              onSaveAsPreset={() => {
                setShowSaveAsPresetModal(true);
              }}
              resultCount={totalHits}
              scope={panelScope}
              setScope={setPanelScope}
            />
          </Suspense>
        ) : null}
      </div>

      <FilterChipsBar
        chips={panelConditions}
        scope={panelScope}
        onClearScope={() => setPanelScope(null)}
        attrLabelMap={{
          brand: t('products.toolbar.filter_brand', { defaultValue: 'Marka' }),
          category: t('products.fields.categories', { defaultValue: 'Kategoria' }),
          completeness_pct: t('products.fields.completeness', { defaultValue: 'Compl.' }),
          enabled: t('products.fields.enabled', { defaultValue: 'Aktywny' }),
          price: t('products.fields.price', { defaultValue: 'Cena' }),
          status: t('products.fields.status', { defaultValue: 'Status' }),
        }}
        onRemove={(idx) => {
          const next = panelConditions.filter((_, i) => i !== idx);
          setPanelConditions(next);
          if (next.length === 0) setActiveSmartPresetId(null);
        }}
        onClearAll={() => {
          setPanelConditions([]);
          setPanelScope(null);
          setActiveSmartPresetId(null);
        }}
        onEditChip={() => setAdvancedPanelOpen(true)}
      />

      <SelectionToolbar
        mode={crossPageSelection.active ? 'all-matching' : selected.size > 0 ? 'page' : 'none'}
        perPageCount={selected.size}
        matchingCount={totalHits}
        totalMatched={crossPageSelection.totalMatched}
        capped={crossPageSelection.capped}
        isLoading={crossPageLoading}
        onSelectAllMatching={() => {
          void (async () => {
            setCrossPageLoading(true);
            try {
              const body: Record<string, unknown> = {
                variants_mode: hasVariants ? variantsMode : 'flat',
                object_type_id: objectTypeId,
              };
              if (activePreset !== undefined) {
                body.smart_preset = activePreset.slug ?? activePreset.id;
              } else if (filterBlob !== undefined) {
                body.filter = filterBlob;
              }
              if (query !== '') body.q = query;
              // UP-06 — the legacy /api/products/select-all-matching endpoint
              // is still product-only; for non-product kinds we cap selection
              // at the current page until a poly-kind variant ships
              // (follow-up). Operator sees a non-fatal toast hint then.
              if (!isProduct) {
                toast.info(
                  t('object_list.select_all_matching_unavailable', {
                    defaultValue:
                      'Cross-page selection dla custom kindów dojdzie w UP-10 follow-upie.',
                  }),
                );
                setCrossPageLoading(false);
                return;
              }
              const response = await jsonFetch<{
                ids: string[];
                totalMatched: number;
                capped: boolean;
              }>('/api/products/select-all-matching', {
                method: 'POST',
                body,
              });
              setSelected(new Set(response.ids));
              setCrossPageSelection({
                active: true,
                totalMatched: response.totalMatched,
                capped: response.capped,
              });
            } catch (err) {
              toast.error(
                httpErrorDetail(err) ??
                  t('products.list.action_failed', { defaultValue: 'Operacja nie powiodła się.' }),
              );
            } finally {
              setCrossPageLoading(false);
            }
          })();
        }}
        onClear={() => {
          setSelected(new Set());
          setCrossPageSelection({ active: false, totalMatched: 0, capped: false });
          setShowSelectedOnly(false);
        }}
      />

      <div className="flex flex-wrap items-center gap-2 text-[12px] text-zinc-500">
        <span className="tabular-nums">
          <span className="text-zinc-900 font-semibold">{totalHits.toLocaleString('pl-PL')}</span>{' '}
          {t('products.counter.results', {
            count: totalHits,
            defaultValue_one: '{{count}} wynik',
            defaultValue_other: '{{count}} wyników',
            defaultValue: '{{count}} wyników',
          })}
        </span>
        {selected.size > 0 ? (
          <>
            <span className="text-zinc-300">·</span>
            <span className="tabular-nums">
              <span className="text-zinc-900 font-semibold">{selected.size}</span>{' '}
              {t('products.counter.selected', {
                count: selected.size,
                defaultValue: 'zaznaczonych',
              })}
            </span>
            <button
              type="button"
              onClick={() => {
                setShowSelectedOnly((prev) => !prev);
              }}
              aria-pressed={showSelectedOnly}
              className={cn(
                'ml-1 inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg text-[12px] font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900',
                showSelectedOnly
                  ? 'bg-orange-600 text-white hover:bg-orange-500'
                  : 'bg-orange-100 text-orange-700 hover:bg-orange-200',
              )}
            >
              {showSelectedOnly
                ? t('products.counter.show_all', { defaultValue: 'Pokaż wszystkie' })
                : t('products.counter.show_selected_only', {
                    defaultValue: 'Pokaż tylko zaznaczone',
                  })}
            </button>
          </>
        ) : null}
      </div>

      {showEmptyState ? (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white px-8 py-12 text-center">
          <p className="text-base font-medium text-zinc-700">
            {t('object_list.empty.title', {
              defaultValue: 'Lista {{label}} jest pusta',
              label: objectTypeLabel,
            })}
          </p>
          <p className="mt-1 text-sm text-zinc-500">
            {t('object_list.empty.description', {
              defaultValue: 'Dodaj pierwszy obiekt, żeby rozpocząć.',
            })}
          </p>
          <Button asChild className="mt-4 h-10 rounded-xl">
            <Link to={createPath}>
              <Plus className="size-4" />
              {t('object_list.empty.cta', { defaultValue: 'Dodaj pierwszy' })}
            </Link>
          </Button>
        </div>
      ) : viewMode === 'excel' ? (
        <ExcelLikeGrid<ExcelObjectRow>
          rows={visible.map((row) => toExcelRow(row, visibleColumns, uiLocale, optionLabels))}
          columns={excelColumns}
          density={density}
          onColumnResize={handleColumnResize}
          onPasteReport={(applied, skipped) => {
            if (skipped > 0) {
              toast.info(
                t('grid.paste.report_mixed', {
                  applied,
                  skipped,
                  defaultValue: 'Wklejono {{applied}}, pominięto {{skipped}}',
                }),
              );
            } else if (applied > 0) {
              toast.success(
                t('grid.paste.report_ok', {
                  applied,
                  defaultValue: 'Wklejono {{applied}} komórek',
                }),
              );
            }
          }}
          onCommit={(rowIdx, colKey, value) => {
            const row = visible[rowIdx];
            if (row === undefined) return;
            void handleExcelCommit(row, colKey, value);
          }}
        />
      ) : (
        <ProductsGrid
          rows={visible}
          columns={visibleColumns}
          optionLabels={optionLabels}
          showMediaColumn={hasMultimedia}
          density={density}
          onColumnResize={handleColumnResize}
          sort={isSearchActive ? null : sort}
          onSortChange={isSearchActive ? undefined : handleSortChange}
          selected={selected}
          onToggleSelect={toggleSelect}
          onToggleSelectAll={toggleSelectAll}
          expandedMasters={expandedMasters}
          onToggleExpand={toggleExpand}
          variantsByMasterCount={variantsByMasterCount}
          onChangedRow={refetch}
          isLoading={isLoading}
          alwaysShowChevronOnMasters={hasVariants && variantsMode === 'tree' && !isSearchActive}
          detailPathFor={detailPathFor}
        />
      )}

      <PaginationBar
        page={page}
        pageSize={pageSize}
        totalItems={totalHits}
        onPageChange={setPage}
        onPageSizeChange={(next) => {
          setPageSize(next);
          setPage(1);
        }}
      />

      <BulkBar
        selectedIds={Array.from(selected)}
        onClear={() => {
          setSelected(new Set());
          setShowSelectedOnly(false);
        }}
        onApplied={onBulkApplied}
        onOpenWizard={() => setBulkWizardOpen(true)}
        onOpenCategoryModal={isCategorizable ? () => setBulkCategoryOpen(true) : undefined}
        onOpenDeleteModal={() => setBulkDeleteOpen(true)}
        onOpenDuplicateModal={isProduct ? () => setBulkDuplicateOpen(true) : undefined}
        onOpenGenerateContent={isProduct ? () => setBulkGenerateOpen(true) : undefined}
        onOpenChangeStatus={isProduct ? () => setBulkStatusOpen(true) : undefined}
        onOpenCmdK={() => setCmdKOpen(true)}
        onOpenExportModal={
          isProduct || isCustomKind ? () => goToExport('selected', Array.from(selected)) : undefined
        }
      />

      <Suspense fallback={null}>
        {bulkWizardOpen ? (
          <BulkWizard
            open={bulkWizardOpen}
            selectedIds={Array.from(selected)}
            onClose={() => setBulkWizardOpen(false)}
            onApplied={(result) => {
              setLastBulkSession(result);
              setSelected(new Set());
              setShowSelectedOnly(false);
              refetch();
            }}
          />
        ) : null}

        {bulkCategoryOpen ? (
          <BulkCategoryModal
            selectedIds={Array.from(selected)}
            onClose={() => setBulkCategoryOpen(false)}
            onApplied={(result) => {
              setLastBulkSession(result);
              setSelected(new Set());
              setShowSelectedOnly(false);
              refetch();
            }}
          />
        ) : null}

        {bulkDeleteOpen ? (
          <BulkDeleteConfirmModal
            selectedIds={Array.from(selected)}
            onClose={() => setBulkDeleteOpen(false)}
            onApplied={(result) => {
              setLastBulkSession(result);
              setSelected(new Set());
              setShowSelectedOnly(false);
              refetch();
            }}
          />
        ) : null}

        {bulkDuplicateOpen ? (
          <BulkDuplicateModal
            selectedIds={Array.from(selected)}
            onClose={() => setBulkDuplicateOpen(false)}
            onApplied={(result) => {
              setLastBulkSession(result);
              setSelected(new Set());
              setShowSelectedOnly(false);
              refetch();
            }}
          />
        ) : null}

        {bulkStatusOpen ? (
          <BulkChangeStatusDialog
            ids={Array.from(selected)}
            open={bulkStatusOpen}
            onOpenChange={setBulkStatusOpen}
            onApplied={onBulkApplied}
          />
        ) : null}

        {bulkGenerateOpen ? (
          <BulkGenerateContentModal
            selectedIds={Array.from(selected)}
            objectTypeCode={objectTypeCode}
            onClose={() => setBulkGenerateOpen(false)}
            onStarted={() => {
              setSelected(new Set());
              setShowSelectedOnly(false);
            }}
          />
        ) : null}

        <CmdKPalette
          open={cmdKOpen}
          onClose={() => setCmdKOpen(false)}
          objectTypeCode={objectTypeCode}
          filterDsl={panelDsl}
          selectedIds={Array.from(selected)}
          totalMatching={
            crossPageSelection.active ? crossPageSelection.totalMatched : selected.size
          }
        />
      </Suspense>

      <RollbackToast
        session={lastBulkSession}
        onDismiss={() => setLastBulkSession(null)}
        onRolledBack={() => {
          refetch();
        }}
      />

      {showSaveAsPresetModal ? (
        <SaveAsSmartPresetModal
          query={panelDsl}
          // PTR-01 — snapshot only the columns currently on the list (the
          // visible set, in order), not the full 200+ attribute catalogue.
          columns={overridesFromColumns(visibleColumns)}
          create={createSmartPreset}
          onClose={() => setShowSaveAsPresetModal(false)}
          onSaved={(preset) => {
            toast.success(
              t('products.smart_filters.save_success', {
                defaultValue: 'Smart Preset zapisany',
              }),
            );
            setActiveSmartPresetId(preset.id);
          }}
        />
      ) : null}
    </div>
  );
}

function searchHitToRow(hit: CatalogSearchHit): ProductsGridRow {
  return buildRow({
    id: hit.id,
    code: hit.code ?? hit.id,
    enabled: hit.enabled,
    status: hit.status,
    attributesIndexed: hit.attributesIndexed,
    createdAt: undefined,
    updatedAt: undefined,
  });
}

function catalogObjectToRow(entry: CatalogObjectListEntry): ProductsGridRow {
  return buildRow(entry);
}

function buildRow(entry: CatalogObjectListEntry): ProductsGridRow {
  const attrs = unwrapAttributesIndexed(entry.attributesIndexed);
  const name = typeof attrs.name === 'string' ? attrs.name : entry.code;
  const variantAxis = readString(attrs, ['variant_axis', 'axis']);
  const categories = readCategories(attrs);
  const price = readPrice(attrs);
  const parentId =
    typeof entry.parentId === 'string'
      ? entry.parentId
      : entry.parent && typeof entry.parent.id === 'string'
        ? entry.parent.id
        : null;
  return {
    id: entry.id,
    sku: entry.code,
    name,
    categories,
    price,
    completenessPct: typeof entry.completenessPct === 'number' ? entry.completenessPct : 0,
    syncStatusAggregate: normaliseSyncAggregate(entry.syncStatusAggregate),
    enabled: entry.enabled !== false,
    status: typeof entry.status === 'string' ? entry.status : null,
    parentId,
    variantAxis,
    updatedAt: typeof entry.updatedAt === 'string' ? entry.updatedAt : null,
    attributesIndexed: entry.attributesIndexed ?? null,
  };
}

function readString(attrs: Record<string, unknown>, keys: ReadonlyArray<string>): string | null {
  for (const key of keys) {
    const value = attrs[key];
    if (typeof value === 'string' && value.length > 0) return value;
  }
  return null;
}

function readCategories(attrs: Record<string, unknown>): string[] | null {
  const raw = attrs.categories ?? attrs.category_codes;
  if (!Array.isArray(raw)) return null;
  const out: string[] = [];
  for (const entry of raw) {
    if (typeof entry === 'string') out.push(entry);
  }
  return out.length > 0 ? out : null;
}

function readPrice(attrs: Record<string, unknown>): { amount: number; currency: string } | null {
  const raw = attrs.price ?? attrs.list_price;
  if (raw === undefined || raw === null) return null;
  if (typeof raw === 'number') return { amount: raw, currency: 'PLN' };
  if (typeof raw === 'object') {
    const obj = raw as Record<string, unknown>;
    const amount = typeof obj.amount === 'number' ? obj.amount : null;
    const currency = typeof obj.currency === 'string' ? obj.currency : 'PLN';
    if (amount !== null) return { amount, currency };
  }
  return null;
}

function normaliseSyncAggregate(raw: string | undefined): SyncAggregate {
  if (raw === 'green' || raw === 'yellow' || raw === 'red' || raw === 'gray') {
    return raw;
  }
  return 'gray';
}
