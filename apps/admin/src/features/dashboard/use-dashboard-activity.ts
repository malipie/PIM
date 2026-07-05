import { useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

export type ActivityRange = '7d' | '30d' | '90d';

export const ACTIVITY_RANGES: readonly ActivityRange[] = ['7d', '30d', '90d'];

export function isActivityRange(value: string | null): value is ActivityRange {
  return null !== value && (ACTIVITY_RANGES as readonly string[]).includes(value);
}

/** 1:1 with GET /api/dashboard/activity (DASH-07). */
export interface DashboardActivityDto {
  range: ActivityRange;
  /** Contiguous daily series, gap-filled, oldest → newest. */
  series: Array<{ date: string; added: number; modified: number }>;
  addedTotal: number;
  modifiedTotal: number;
  avgPerDay: number;
}

/** 1:1 with GET /api/dashboard/top-edited (DASH-07). */
export interface DashboardTopEditedDto {
  items: Array<{ id: string; name: string; sku: string; completenessPct: number; edits: number }>;
}

/**
 * DASH-08 (#2263) — live team-activity series for the dashboard chart.
 * Data is null while degraded (per-widget "—" state, brief §8.3); React
 * Query keeps the last known series through transient errors.
 */
export function useDashboardActivity(range: ActivityRange) {
  return useQuery({
    queryKey: ['dashboard-activity', range],
    staleTime: 60_000,
    refetchOnWindowFocus: false,
    queryFn: async (): Promise<DashboardActivityDto> =>
      jsonFetch<DashboardActivityDto>('/api/dashboard/activity', { query: { range } }),
  });
}

/** DASH-08 (#2263) — most-edited products ranking for the same window. */
export function useDashboardTopEdited(range: ActivityRange) {
  return useQuery({
    queryKey: ['dashboard-top-edited', range],
    staleTime: 60_000,
    refetchOnWindowFocus: false,
    queryFn: async (): Promise<DashboardTopEditedDto> =>
      jsonFetch<DashboardTopEditedDto>('/api/dashboard/top-edited', {
        query: { range, limit: 6 },
      }),
  });
}
