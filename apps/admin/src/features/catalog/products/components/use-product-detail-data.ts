import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';

import { useListSchema } from '@/hooks/use-list-schema';
import { unwrapAttributesIndexed } from '@/lib/attributes-indexed';
import { HttpError, jsonFetch } from '@/lib/http';
import { objectKeys } from '@/lib/object-query-keys';
import { useDefaultObjectType } from '../use-default-object-type';
import { scopeQuery } from './scope';
import type {
  CatalogObjectDto,
  ChannelOption,
  GroupMeta,
  LocaleOption,
  ProductChannel,
  ProductDetailMode,
  ProductLocale,
  ScopeStatus,
} from './types';

interface UseProductDetailDataArgs {
  mode: ProductDetailMode;
  id: string;
  isEditMode: boolean;
  createObjectTypeId: string | undefined;
  locale: ProductLocale;
  channel: ProductChannel | null;
  createCategoryIds: string[];
  createPrimaryId: string | null;
}

/**
 * AUD-057 (#1608) — every server read the product detail screen performs,
 * lifted out of product-detail-page.tsx so the page composes data + UI
 * instead of inlining ~250 lines of useQuery + derived memos. The page
 * still owns the locale/channel UI state and the dirty-field write path;
 * this hook owns the read side and the values derived purely from it.
 *
 * All reads go through useQuery (not jsonFetch+useEffect) so cache
 * invalidation from sibling tabs (categories, relations) actually refetches
 * — the same stale-data discipline as ADR-0021.
 */
export function useProductDetailData({
  mode,
  id,
  isEditMode,
  createObjectTypeId,
  locale,
  channel,
  createCategoryIds,
  createPrimaryId,
}: UseProductDetailDataArgs) {
  // #1150 / #1155 — load the product in the active locale + channel so
  // localizable / channel-scoped values reflect the picker. locale +
  // channel are part of the query key, so switching either refetches the
  // scope-resolved reading.
  const productQuery = useQuery<CatalogObjectDto>({
    queryKey: objectKeys.scoped(id, locale, channel),
    queryFn: () =>
      jsonFetch<CatalogObjectDto>(`/api/objects/${id}${scopeQuery(locale, channel)}`, {
        accept: 'application/ld+json',
      }),
    enabled: isEditMode && id !== '',
    // A 404 is conclusive — retrying it three times keeps the operator on
    // "Ładowanie…" for ~8s before the not-found state appears (#1043).
    // #2881 — 403 joins 404 as conclusive. Retrying a refused read three
    // times only delays the message, and the delay is what made the page
    // look like it loaded before failing.
    retry: (failureCount, error) =>
      !(error instanceof HttpError && [403, 404].includes(error.status)) && failureCount < 3,
  });

  const { objectTypeId: defaultProductTypeId } = useDefaultObjectType('product');
  const loadedProduct = isEditMode ? (productQuery.data ?? null) : null;
  // Edit mode reads capabilities from the object's OWN ObjectType (any
  // kind); create mode takes the route-resolved ObjectType (#1415) and
  // falls back to the built-in product for /products/new.
  const objectTypeId = isEditMode
    ? (loadedProduct?.objectType?.id ?? null)
    : (createObjectTypeId ?? defaultProductTypeId);

  const schemaQuery = useListSchema(objectTypeId ?? undefined);
  const schemaObjectType = schemaQuery.data?.objectType;
  const kind = isEditMode
    ? (loadedProduct?.kind ?? null)
    : (schemaObjectType?.kind ?? (createObjectTypeId === undefined ? 'product' : null));
  const hasMultimediaCapability = schemaObjectType?.has_multimedia ?? false;
  const hasVariantsCapability = schemaObjectType?.has_variants ?? kind === 'product';
  const isCategorizable = schemaObjectType?.is_categorizable ?? kind === 'product';

  // Resolve the category codes for chip rendering in create mode. Same
  // query key as `CategoryPickerDialog` so opening the picker hits the
  // shared cache — and so the sidebar updates the moment the picker
  // commits a new selection.
  const categoriesListQuery = useQuery({
    queryKey: ['categories', 'picker'],
    queryFn: () =>
      jsonFetch<{
        'hydra:member'?: Array<{ id: string; code: string }>;
        member?: Array<{ id: string; code: string }>;
      }>('/api/categories?itemsPerPage=200'),
    enabled: mode === 'create' && createCategoryIds.length > 0,
    staleTime: 60_000,
  });

  const createCategoriesSummaries = useMemo(() => {
    if (mode !== 'create') return [] as { categoryId: string; code: string; isPrimary: boolean }[];
    const rows =
      categoriesListQuery.data?.['hydra:member'] ?? categoriesListQuery.data?.member ?? [];
    const codeById = new Map<string, string>();
    for (const row of rows) codeById.set(row.id, row.code);
    return createCategoryIds.map((cid) => ({
      categoryId: cid,
      code: codeById.get(cid) ?? cid.slice(0, 8),
      isPrimary: cid === createPrimaryId,
    }));
  }, [mode, createCategoryIds, createPrimaryId, categoriesListQuery.data]);

  const product = loadedProduct;
  const attrs = useMemo(
    () =>
      unwrapAttributesIndexed(product?.attributesIndexed as Record<string, unknown> | undefined),
    [product?.attributesIndexed],
  );

  // #891 — fetch effective groups via useQuery so CategoriesTab cache
  // invalidation actually triggers refetch. In create mode the query keys
  // flip between the preview endpoint (when categories are selected) and
  // the bare ObjectType endpoint (when none are selected yet).
  const groupsQuery = useQuery<{ groups: GroupMeta[]; locales?: LocaleOption[] }>({
    queryKey:
      isEditMode && id !== ''
        ? objectKeys.effectiveGroups(id)
        : [
            'object-types',
            objectTypeId,
            'effective-attribute-groups',
            createCategoryIds.length > 0 ? [...createCategoryIds].sort() : 'base',
          ],
    queryFn: async () => {
      if (isEditMode && id !== '') {
        return jsonFetch<{ groups: GroupMeta[]; locales?: LocaleOption[] }>(
          `/api/objects/${id}/effective-attribute-groups`,
        );
      }
      if (objectTypeId === null) {
        return { groups: [] };
      }
      // #1415 — there is no GET for OT-level effective groups; the preview
      // POST serves both the bare schema (empty categoryIds) and the
      // category-driven overlay.
      return jsonFetch<{ groups: GroupMeta[]; locales?: LocaleOption[] }>(
        `/api/object_types/${objectTypeId}/effective-attribute-groups/preview`,
        {
          method: 'POST',
          contentType: 'application/json',
          accept: 'application/json',
          body: { categoryIds: createCategoryIds },
        },
      );
    },
    enabled: isEditMode ? id !== '' : objectTypeId !== null,
    staleTime: 5_000,
    placeholderData: (prev) => prev,
  });

  // Empty groups (no attributes after field-level filtering) render as bare
  // headers with zero rows — drop them, consistent with #1348's "no empty
  // Atrybuty tab" rule. #1415 — the header already carries a dedicated input
  // for the `name` attribute; the duplicated group row confused operators.
  const groups = useMemo(
    () =>
      (groupsQuery.data?.groups ?? [])
        .map((g) => ({
          ...g,
          attributes: g.attributes.filter((attr) => attr.code !== 'name'),
        }))
        .filter((g) => g.attributes.length > 0),
    [groupsQuery.data],
  );

  const locales = useMemo<LocaleOption[]>(
    () => groupsQuery.data?.locales ?? [],
    [groupsQuery.data],
  );

  // #1155 — channel picker fed from /api/channels (tenant's real channels).
  const channelsQuery = useQuery<ChannelOption[]>({
    queryKey: ['channels', 'picker'],
    queryFn: async () => {
      const response = await jsonFetch<{ member?: ChannelOption[] } | ChannelOption[]>(
        '/api/channels',
        { accept: 'application/ld+json' },
      );
      return Array.isArray(response) ? response : (response.member ?? []);
    },
    enabled: isEditMode,
    staleTime: 60_000,
  });
  const channels = channelsQuery.data ?? [];

  // #1222 — scope-status: per-attribute inherited indicator. Only in edit
  // mode, and only when a non-primary locale is active (primary locale
  // values are global — nothing can be "inherited from another locale").
  const scopeStatusQuery = useQuery<ScopeStatus>({
    queryKey: objectKeys.scopeStatus(id, locale, channel),
    queryFn: () =>
      jsonFetch<ScopeStatus>(`/api/objects/${id}/scope-status${scopeQuery(locale, channel)}`),
    enabled: isEditMode && id !== '' && locale !== null,
    staleTime: 30_000,
  });
  const scopeStatus = scopeStatusQuery.data ?? {};

  // MODR-03 (#925) — tab list derived dynamically from `groups`: every group
  // with `display_mode='tab'` becomes its own tab; `display_mode='stacked'`
  // (and the synthetic "default" bucket) collect into the `attributes` tab.
  const tabModeGroups = useMemo(
    () => groups.filter((g) => (g.display_mode ?? 'tab') === 'tab'),
    [groups],
  );
  const stackedGroups = useMemo(
    () => groups.filter((g) => (g.display_mode ?? 'tab') === 'stacked'),
    [groups],
  );

  // MODR-06 (#928) — lightweight probe for incoming links so the
  // "Powiązania" tab can surface even when the object has no forward
  // relation attributes (e.g. a Category referenced from products).
  const reverseRelationsQuery = useQuery<{ hasReverse: boolean; count: number }>({
    queryKey: ['objects', id, 'relations', 'reverse', 'count'],
    queryFn: () =>
      jsonFetch<{ hasReverse: boolean; count: number }>(
        `/api/objects/${id}/relations/reverse/count`,
      ),
    enabled: isEditMode && id !== '',
    staleTime: 30_000,
  });
  const hasReverseRelations = reverseRelationsQuery.data?.hasReverse ?? false;

  return {
    productQuery,
    product,
    objectTypeId,
    kind,
    hasMultimediaCapability,
    hasVariantsCapability,
    isCategorizable,
    attrs,
    groupsQuery,
    groups,
    locales,
    channels,
    scopeStatus,
    tabModeGroups,
    stackedGroups,
    hasReverseRelations,
    categoriesListQuery,
    createCategoriesSummaries,
  };
}
