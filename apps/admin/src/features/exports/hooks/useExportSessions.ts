import { useQuery, useQueryClient } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/** Summary row from `GET /api/exports/sessions` (EXR-04 + EXR-08 fields). */
export interface ExportSessionRow {
  id: string;
  entity_type:
    | 'product'
    | 'custom_module'
    | 'module_schema'
    | 'attributes_groups'
    | 'categories'
    | string;
  object_type_id: string | null;
  format: 'xlsx' | 'csv' | 'xml';
  target_scope: string;
  target_count: number;
  success_count: number;
  status: 'pending' | 'running' | 'done' | 'error' | 'cancelled';
  source: string;
  started_at: string;
  completed_at: string | null;
  profile_name: string | null;
  file_path: string | null;
  duration_ms: number | null;
  error_message: string | null;
}

export interface ExportSessionsResponse {
  items: ExportSessionRow[];
  total: number;
}

export const EXPORT_SESSIONS_KEY = ['exports', 'sessions'] as const;

/**
 * Shared sessions query (EXR-08): the layout tab counter and the
 * sessions view read the same cache entry. 30 s refetch keeps the
 * history fresh until EXR-15 wires the Mercure stream for instant
 * invalidation (the stream consumer already calls `refetch`).
 */
export function useExportSessions() {
  return useQuery<ExportSessionsResponse>({
    queryKey: EXPORT_SESSIONS_KEY,
    queryFn: () =>
      jsonFetch<ExportSessionsResponse>('/api/exports/sessions', { accept: 'application/json' }),
    refetchInterval: 30_000,
    staleTime: 5_000,
  });
}

/** Imperative invalidation used by the Mercure stream consumer + actions. */
export function useInvalidateExportSessions(): () => void {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: EXPORT_SESSIONS_KEY });
  };
}
