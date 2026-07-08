import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

export type AlertWindow = '1d' | '7d' | '30d';
export type AlertSeverity = 'critical' | 'warning';
export type AlertType =
  | 'sync_run'
  | 'import_session'
  | 'feed_run'
  | 'webhook'
  | 'completeness_drop';

export interface DashboardAlertItem {
  fingerprint: string;
  type: AlertType;
  severity: AlertSeverity;
  /** ISO-ish timestamp from the source row; rendered relative FE-side. */
  occurredAt: string;
  /** Structured params — the FE composes the title via i18n (pl/en parity). */
  params: Record<string, unknown>;
  context: Record<string, string>;
}

/** 1:1 with GET /api/dashboard/alerts (DASH-09). */
export interface DashboardAlertsDto {
  total: number;
  critical: number;
  warnings: number;
  allCount: number;
  items: DashboardAlertItem[];
}

const alertsKey = (window: AlertWindow, limit: number) =>
  ['dashboard-alerts', window, limit] as const;

/**
 * DASH-10 (#2273) — the live action-center feed. Short staleTime so an
 * ack elsewhere (or a new failure) surfaces promptly; React Query keeps
 * the last feed through transient errors (brief §8.3).
 */
export function useDashboardAlerts(window: AlertWindow, limit: number) {
  return useQuery({
    queryKey: alertsKey(window, limit),
    staleTime: 30_000,
    refetchOnWindowFocus: true,
    queryFn: async (): Promise<DashboardAlertsDto> =>
      jsonFetch<DashboardAlertsDto>('/api/dashboard/alerts', { query: { window, limit } }),
  });
}

/**
 * DASH-10 (#2273) — acknowledge ("oznacz jako przeczytane"). Optimistic:
 * the item vanishes and the counters decrement immediately; on error the
 * snapshot is restored. onSettled re-syncs the feed AND the summary
 * (the KPI "Otwarte alerty" tile reads summary.openAlerts).
 */
export function useAckAlert(window: AlertWindow, limit: number) {
  const queryClient = useQueryClient();
  const key = alertsKey(window, limit);

  return useMutation({
    mutationFn: async (fingerprint: string): Promise<void> => {
      await jsonFetch(`/api/dashboard/alerts/${encodeURIComponent(fingerprint)}/ack`, {
        method: 'POST',
      });
    },
    onMutate: async (fingerprint: string) => {
      await queryClient.cancelQueries({ queryKey: key });
      const previous = queryClient.getQueryData<DashboardAlertsDto>(key);
      if (previous !== undefined) {
        const removed = previous.items.find((item) => item.fingerprint === fingerprint);
        const items = previous.items.filter((item) => item.fingerprint !== fingerprint);
        const criticalDrop = removed?.severity === 'critical' ? 1 : 0;
        const warningDrop = removed?.severity === 'warning' ? 1 : 0;
        queryClient.setQueryData<DashboardAlertsDto>(key, {
          ...previous,
          items,
          total: Math.max(0, previous.total - (removed ? 1 : 0)),
          critical: Math.max(0, previous.critical - criticalDrop),
          warnings: Math.max(0, previous.warnings - warningDrop),
          allCount: Math.max(0, previous.allCount - (removed ? 1 : 0)),
        });
      }
      return { previous };
    },
    onError: (_error, _fingerprint, context) => {
      if (context?.previous !== undefined) {
        queryClient.setQueryData(key, context.previous);
      }
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ['dashboard-alerts'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard-summary'] });
    },
  });
}
