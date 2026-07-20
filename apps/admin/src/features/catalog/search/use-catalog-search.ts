import { useEffect, useState } from 'react';

import { jsonFetch } from '@/lib/http';

/**
 * Generic catalog search hook (#53 / 0.5.5).
 *
 * Calls the per-kind endpoint from #52 (`/api/search/products`,
 * `/api/search/categories`, `/api/search/assets`) with debounced
 * query input, optional facet filter map, and offset pagination.
 *
 * The debounce keeps the network quiet while the user types — 300 ms
 * is the standard "I want autocomplete to feel snappy but not flood
 * Meili" interval. The hook re-fetches when query, filters, or page
 * change.
 */
export type CatalogSearchKind = 'products' | 'categories' | 'assets';

/**
 * UP-06 (#1024) — universal search target. `kind` hits the existing
 * sugar route `/api/search/{kind}` (products / categories / assets);
 * `objectTypeId` hits `/api/search/objects?objectTypeId=...` and scopes
 * the consolidated Meilisearch `objects` index to one ObjectType —
 * essential for custom kinds where `kind='custom'` would otherwise
 * mix two unrelated ObjectTypes in the same result.
 */
export type CatalogSearchTarget =
  | { kind: CatalogSearchKind; objectTypeId?: undefined }
  | { kind?: undefined; objectTypeId: string };

export interface CatalogSearchHit {
  id: string;
  code?: string;
  kind?: string;
  status?: string;
  enabled?: boolean;
  attributesIndexed?: Record<string, unknown>;
  _formatted?: Record<string, unknown>;
}

export interface CatalogSearchResult {
  hits: CatalogSearchHit[];
  totalHits: number;
  facetDistribution: Record<string, Record<string, number>>;
  processingTimeMs: number;
  page: number;
  perPage: number;
  /**
   * #2673 — true when a value-context scope matched more objects than the
   * SQL→Meili prefilter cap (10k); the hit list is then approximate.
   */
  scopeTruncated?: boolean;
}

export type UseCatalogSearchOptions = CatalogSearchTarget & UseCatalogSearchBaseOptions;

interface UseCatalogSearchBaseOptions {
  query: string;
  filters?: Record<string, string | string[]>;
  /**
   * Range / numeric filters (UI-02.24). Each key is mapped to
   * `filter[key][gte]=N` / `filter[key][lte]=N` query params; the
   * search controller forwards them to Meilisearch as
   * `key >= N AND key <= N` filter expressions.
   */
  rangeFilters?: Record<string, { gte?: number; lte?: number }>;
  /**
   * VIEW-10 (#538) — smart filter preset to apply server-side. Passed
   * as `?smart_preset=<slug-or-id>`; BE resolver fetches the preset DSL,
   * compiles to a Meilisearch filter expression, and AND-merges with
   * the flat filters above.
   */
  smartPresetId?: string;
  /**
   * VIEW-10 (#538) — base64-encoded FilterDsl blob for nested groups
   * (or oversized single-level conditions). Passed as `?q=<blob>`.
   * Mutually exclusive with `smartPresetId`; if both are supplied,
   * `smartPresetId` wins server-side.
   */
  filterBlob?: string;
  facets?: string[];
  page?: number;
  perPage?: number;
  highlight?: boolean;
  debounceMs?: number;
}

export interface UseCatalogSearchState {
  result: CatalogSearchResult | null;
  isLoading: boolean;
  error: Error | null;
}

export function useCatalogSearch(options: UseCatalogSearchOptions): UseCatalogSearchState {
  const {
    kind,
    objectTypeId,
    query,
    filters,
    rangeFilters,
    smartPresetId,
    filterBlob,
    facets,
    page = 1,
    perPage = 30,
    highlight = false,
    debounceMs = 300,
  } = options;

  const [result, setResult] = useState<CatalogSearchResult | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);

  useEffect(() => {
    let cancelled = false;
    const handle = window.setTimeout(() => {
      const params = new URLSearchParams();
      // VIEW-20 (#551) — text search lives under `?query=`; `?q=` is reserved
      // for the base64 filter blob (VIEW-10).
      if (query !== '') params.set('query', query);
      params.set('page', String(page));
      params.set('perPage', String(perPage));
      if (highlight) params.set('highlight', 'true');
      if (facets && facets.length > 0) params.set('facets', facets.join(','));
      if (filters) {
        for (const [key, value] of Object.entries(filters)) {
          if (Array.isArray(value)) {
            for (const item of value) params.append(`filter[${key}][]`, item);
          } else {
            params.set(`filter[${key}]`, value);
          }
        }
      }
      if (rangeFilters) {
        for (const [key, range] of Object.entries(rangeFilters)) {
          if (range.gte !== undefined) params.set(`filter[${key}][gte]`, String(range.gte));
          if (range.lte !== undefined) params.set(`filter[${key}][lte]`, String(range.lte));
        }
      }
      if (smartPresetId !== undefined && smartPresetId !== '') {
        params.set('smart_preset', smartPresetId);
      } else if (filterBlob !== undefined && filterBlob !== '') {
        params.set('q', filterBlob);
      }

      setIsLoading(true);
      // UP-06 (#1024) — route choice: built-in sugar (`kind`) vs.
      // universal endpoint (`objectTypeId`). Sugar route is mandatory
      // for built-in kinds because the per-kind facet whitelist /
      // permission code differs; universal handles custom kinds.
      const targetUrl =
        objectTypeId !== undefined
          ? `/api/search/objects?objectTypeId=${encodeURIComponent(objectTypeId)}&${params.toString()}`
          : `/api/search/${kind}?${params.toString()}`;
      jsonFetch<CatalogSearchResult>(targetUrl)
        .then((response) => {
          if (cancelled) return;
          setResult(response);
          setError(null);
        })
        .catch((err: unknown) => {
          if (cancelled) return;
          setError(err instanceof Error ? err : new Error(String(err)));
        })
        .finally(() => {
          if (!cancelled) setIsLoading(false);
        });
    }, debounceMs);

    return () => {
      cancelled = true;
      window.clearTimeout(handle);
    };
  }, [
    kind,
    objectTypeId,
    query,
    page,
    perPage,
    highlight,
    debounceMs,
    facets,
    filters,
    rangeFilters,
    smartPresetId,
    filterBlob,
  ]);

  return { result, isLoading, error };
}
