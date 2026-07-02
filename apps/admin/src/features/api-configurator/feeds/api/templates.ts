import { useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

import type { FeedTemplateKind } from './feeds';

/**
 * XMLF-P5-02 — the wizard's template catalog (GET /api/feeds/templates):
 * the same in-code catalog the backend seeds descriptors from, plus the
 * default mappings the wizard copies into its draft on choice.
 */
export interface FeedTemplateInfo {
  kind: FeedTemplateKind;
  built_in: boolean;
  root_element: string | null;
  namespaces: string[];
  descriptor: Record<string, unknown>;
  default_mappings: Array<Record<string, unknown>>;
}

interface TemplatesResponse {
  items: FeedTemplateInfo[];
}

export function useFeedTemplates() {
  return useQuery({
    queryKey: ['xmlf', 'feed-templates'],
    queryFn: async () => (await jsonFetch<TemplatesResponse>('/api/feeds/templates')).items,
    staleTime: 5 * 60 * 1000,
  });
}

interface ObjectTypeRow {
  id: string;
  kind: string;
}

/**
 * The wizard's ObjectType is locked to Product (MVP), but the create endpoint
 * still requires the tenant's concrete type id — resolve it once.
 */
export function useProductObjectTypeId() {
  return useQuery({
    queryKey: ['xmlf', 'product-object-type'],
    queryFn: async () => {
      // API Platform shape depends on the negotiated format: LD+JSON wraps
      // the collection in `member`, plain JSON returns a bare array.
      const response = await jsonFetch<unknown>('/api/object_types?itemsPerPage=50');
      const rows = Array.isArray(response)
        ? (response as ObjectTypeRow[])
        : ((response as { member?: ObjectTypeRow[] }).member ?? []);
      return rows.find((row) => row.kind === 'product')?.id ?? null;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export interface TenantLocaleRow {
  code: string;
  label: string;
  isActive: boolean;
}

/** Active tenant locales for the scope step's locale select. */
export function useTenantLocales() {
  return useQuery({
    queryKey: ['xmlf', 'tenant-locales'],
    queryFn: async () => {
      const response = await jsonFetch<{ items: TenantLocaleRow[] }>('/api/tenant-locales', {
        accept: 'application/json',
      });
      return response.items.filter((row) => row.isActive);
    },
    staleTime: 5 * 60 * 1000,
  });
}
