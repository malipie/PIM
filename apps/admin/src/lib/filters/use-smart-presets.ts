import { useEffect, useState } from 'react';

import { jsonFetch } from '@/lib/http';

import type { FilterDsl } from './filter-dsl';

/**
 * VIEW-09 (#535) — Smart Filter Presets client. CRUD + counts.
 *
 * Counts are server-side aggregates against the current tenant's
 * `catalog_objects` table (FilterDslResolver compiles DSL → SQL).
 * The endpoint is single-batched (no N+1), so passing `withCounts=true`
 * is cheap.
 */

export interface SmartFilterPreset {
  id: string;
  slug: string;
  name: { pl: string; en: string };
  icon: string;
  query: FilterDsl;
  is_built_in: boolean;
  is_system: boolean;
  sort_order: number;
  count?: number;
  created_at: string;
  updated_at: string;
}

interface ListResponse {
  data: SmartFilterPreset[];
}

export interface UseSmartPresetsOptions {
  withCounts?: boolean;
  /**
   * UP-05 (#1034) — scopes returned presets to a single resource.
   * `products` (legacy default) keeps the existing /products behaviour;
   * an ObjectType code (e.g. `samochody`) restricts to presets created
   * for that custom kind. System-shipped presets (resource=NULL) are
   * always included so global views like "Red completeness" stay
   * available across all resources.
   */
  resource?: string;
}

export interface UseSmartPresetsResult {
  presets: SmartFilterPreset[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => Promise<void>;
  create: (input: {
    name: { pl: string; en: string };
    icon: string;
    query: FilterDsl;
  }) => Promise<SmartFilterPreset>;
  remove: (id: string) => Promise<void>;
}

export function useSmartPresets({
  withCounts = true,
  resource,
}: UseSmartPresetsOptions = {}): UseSmartPresetsResult {
  const [presets, setPresets] = useState<SmartFilterPreset[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const load = async (): Promise<void> => {
    setIsLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (withCounts) params.set('counts', 'true');
      if (resource !== undefined && resource !== '') params.set('resource', resource);
      const url = `/api/smart-filter-presets${params.toString() ? `?${params.toString()}` : ''}`;
      const body = await jsonFetch<ListResponse>(url);
      setPresets(body.data);
    } catch (err) {
      setError(err instanceof Error ? err : new Error(String(err)));
    } finally {
      setIsLoading(false);
    }
  };

  // biome-ignore lint/correctness/useExhaustiveDependencies: `load` is
  // recreated every render; the effect intentionally re-runs only when the
  // scope (withCounts / resource) changes, not on every render.
  useEffect(() => {
    void load();
  }, [withCounts, resource]);

  const create: UseSmartPresetsResult['create'] = async (input) => {
    const created = await jsonFetch<SmartFilterPreset>('/api/smart-filter-presets', {
      method: 'POST',
      // Scope the new preset to the current resource (ObjectType code) so it
      // appears in this list's load(), which filters `resource = :resource OR
      // resource IS NULL`. Without it the backend defaults to 'products' and
      // the preset is invisible on custom-object lists. Refs #1145.
      body: { ...input, resource },
    });
    await load();
    return created;
  };

  const remove: UseSmartPresetsResult['remove'] = async (id) => {
    await jsonFetch<unknown>(`/api/smart-filter-presets/${id}`, { method: 'DELETE' });
    await load();
  };

  return {
    presets,
    isLoading,
    error,
    refetch: load,
    create,
    remove,
  };
}
