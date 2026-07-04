import { type DashboardCompleteness, useDashboardCompleteness } from './use-dashboard-completeness';
import { useDashboardCounts } from './use-dashboard-counts';

export interface DashboardSummary {
  /** Live product total; null = endpoint degraded (caller falls back to mock). */
  productsTotal: number | null;
  /** Live completeness aggregate; null = degraded (caller falls back to mock). */
  completeness: DashboardCompleteness | null;
  isLoading: boolean;
}

/**
 * DASH-02 (#2251) — façade over the two existing dashboard hooks, shaped
 * like the future `GET /api/dashboard/summary` DTO. DASH-06 swaps the
 * internals for the single endpoint without touching the components.
 */
export function useDashboardSummary(): DashboardSummary {
  const counts = useDashboardCounts();
  const completeness = useDashboardCompleteness();

  return {
    productsTotal: counts.data?.products ?? null,
    completeness: completeness.data ?? null,
    isLoading: counts.isLoading || completeness.isLoading,
  };
}

/** pl-PL digit grouping (12847 → "12 847", NBSP separator like the mock). */
export function formatInt(value: number): string {
  return new Intl.NumberFormat('pl-PL').format(value);
}
