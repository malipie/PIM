import { useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/** 1:1 with the BE contract of GET /api/dashboard/summary (DASH-04/05). */
export interface DashboardSummaryDto {
  products: { total: number; delta30d: number | null };
  publishReady: { count: number; pct: number; delta30d: number | null };
  avgCompleteness: {
    pct: number;
    delta30d: number | null;
    weeklyDeltaPoints: number | null;
  };
  /** Cumulative "at least N%" counts for thresholds 25/50/80/100. */
  buckets: Array<{ gte: number; count: number }>;
  /** Worst-first per-channel aggregate. */
  channels: Array<{ code: string; name: string; avgPct: number; readyCount: number }>;
  /** null until the alert aggregator lands (DASH-09). */
  openAlerts: { count: number } | null;
}

export interface DashboardSummary {
  /** null = endpoint degraded and no cached data (per-widget "—" state). */
  summary: DashboardSummaryDto | null;
  isLoading: boolean;
  refetch: () => void;
}

/**
 * DASH-06 (#2259) — one aggregate call replaces the nine count probes the
 * façade used to fan out (4× totalItems + 5× completeness[gte]). The
 * consuming components keep their interface; React Query holds the last
 * known data through transient errors (brief §8.3 per-widget degradation).
 */
export function useDashboardSummary(): DashboardSummary {
  const query = useQuery({
    queryKey: ['dashboard-summary'],
    staleTime: 60_000,
    refetchOnWindowFocus: false,
    queryFn: async (): Promise<DashboardSummaryDto> =>
      jsonFetch<DashboardSummaryDto>('/api/dashboard/summary'),
  });

  return {
    summary: query.data ?? null,
    isLoading: query.isLoading,
    refetch: () => {
      void query.refetch();
    },
  };
}

/** pl-PL digit grouping (12847 → "12 847", NBSP separator like the mock). */
export function formatInt(value: number): string {
  return new Intl.NumberFormat('pl-PL').format(value);
}

/** Signed delta text: 12 → "+12", -3 → "−3" (typographic minus), 0 → "±0". */
export function formatDelta(value: number): string {
  if (value > 0) return `+${formatInt(value)}`;
  if (value < 0) return `−${formatInt(Math.abs(value))}`;
  return '±0';
}
