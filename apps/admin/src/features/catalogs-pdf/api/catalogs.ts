import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/**
 * CPDF-P5-03 — data layer for the catalogs hub. `/api/catalogs` +
 * `/api/catalog-runs/*` are custom `#[Route]` surfaces (not API Platform
 * resources), so the Refine dataProvider's hydra parsing does not apply —
 * plain jsonFetch + react-query, the established pattern for custom endpoints
 * (mirrors the feeds hub `api/feeds.ts`).
 */

export type CatalogStatus = 'active' | 'paused' | 'error';
export type CatalogTemplateKind = 'sheet' | 'grid' | 'pricelist';

/**
 * One row of `GET /api/catalogs` — the fields the hub reads. Exact shape from
 * {@link CatalogProfileController::serialize}. The plaintext token is never
 * serialized; `has_token` tells the card whether a URL already exists.
 */
export interface CatalogProfileRow {
  id: string;
  code: string;
  name: string;
  template_kind: CatalogTemplateKind | string;
  status: CatalogStatus | string;
  object_type_id: string;
  branding: Record<string, unknown>;
  field_mappings: Array<Record<string, unknown>>;
  filter: Record<string, unknown> | null;
  channel_id: string | null;
  publication_channel: string | null;
  locale: string | null;
  renderer: string;
  cached_file_size: number | null;
  cached_page_count: number | null;
  cached_at: string | null;
  has_token: boolean;
  created_at: string;
  updated_at: string;
}

/** `GET /api/catalog-runs/kpi` — {@link CatalogRunMonitorController::kpi}. */
export interface CatalogKpi {
  regenerations_24h: number;
  items_24h: number;
  errors_24h: number;
  pages_published: number;
  last_regenerated_at: string | null;
  catalogs: { active: number; paused: number; error: number };
  last_error: { run_id: string; catalog_id: string; message: string | null } | null;
}

/** Minted URL token — the plaintext URL is returned once (P3-02). */
export interface MintedCatalogToken {
  token: string;
  url: string;
}

interface CatalogsResponse {
  items: CatalogProfileRow[];
}

export const CATALOGS_QUERY_KEY = ['catalogs-pdf', 'profiles'] as const;
export const CATALOG_KPI_QUERY_KEY = ['catalogs-pdf', 'kpi'] as const;

export function useCatalogs() {
  return useQuery({
    queryKey: CATALOGS_QUERY_KEY,
    queryFn: async () => (await jsonFetch<CatalogsResponse>('/api/catalogs')).items,
    staleTime: 30_000,
  });
}

export function useCatalogKpi() {
  return useQuery({
    queryKey: CATALOG_KPI_QUERY_KEY,
    queryFn: () => jsonFetch<CatalogKpi>('/api/catalog-runs/kpi'),
    staleTime: 30_000,
  });
}

function invalidateCatalogs(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: CATALOGS_QUERY_KEY });
  void queryClient.invalidateQueries({ queryKey: CATALOG_KPI_QUERY_KEY });
}

/** Trigger a manual regeneration (202 + pending run). */
export function useRegenerateCatalog() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) =>
      jsonFetch<Record<string, unknown>>(`/api/catalogs/${id}/generate`, { method: 'POST' }),
    onSettled: () => invalidateCatalogs(queryClient),
  });
}

/** Mint/rotate the URL token — the plaintext URL is shown once (P3-02). */
export function useMintCatalogToken() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) =>
      jsonFetch<MintedCatalogToken>(`/api/catalogs/${id}/token`, { method: 'POST' }),
    onSettled: () => invalidateCatalogs(queryClient),
  });
}

/** Delete a catalog (204). */
export function useDeleteCatalog() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) => jsonFetch(`/api/catalogs/${id}`, { method: 'DELETE' }),
    onSettled: () => invalidateCatalogs(queryClient),
  });
}

/** Map a catalog `status` onto a `<StatusPill>` visual variant. */
export function catalogStatusVariant(status: string): 'success' | 'warning' | 'error' {
  switch (status) {
    case 'paused':
      return 'warning';
    case 'error':
      return 'error';
    default:
      return 'success';
  }
}

/** Prefix a same-origin path with the current origin for share/download URLs. */
export function absoluteUrl(path: string): string {
  return path.startsWith('http') ? path : `${window.location.origin}${path}`;
}
