import { useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/**
 * XMLF-P5-05 — run history + drill-down data layer over the P4-03 read API
 * and the P4-02 Mercure stream (`tenant/{tid}/feeds/{feedId}/runs`). REST is
 * the source of truth; the SSE event is a "something changed" signal that
 * drives the live pipeline and triggers refetches.
 */

export type RunHealth = 'success' | 'warning' | 'error' | 'pending' | 'running' | 'cancelled';

export interface FeedRunRow {
  id: string;
  feed_id: string;
  feed_name?: string | null;
  feed_code?: string | null;
  template_kind?: string | null;
  trigger: string;
  status: string;
  health: RunHealth;
  item_count: number;
  skipped_count: number;
  warning_count: number;
  file_size_bytes: number | null;
  duration_ms: number | null;
  error_message: string | null;
  started_at: string;
  completed_at: string | null;
}

export interface FeedRunLogRow {
  id: string;
  level: 'info' | 'warning' | 'error';
  object_sku: string | null;
  slot: string | null;
  message: string;
  created_at: string;
}

interface RunsPage {
  items: FeedRunRow[];
  next_cursor: string | null;
}

export function useFeedRuns(
  feedId: string | null,
  status: 'all' | 'success' | 'warning' | 'error',
) {
  const query = status === 'all' ? '' : `?status=${status}`;
  return useQuery({
    queryKey: ['xmlf', 'feed-runs', feedId ?? 'global', status],
    enabled: feedId !== null,
    queryFn: async () => (await jsonFetch<RunsPage>(`/api/feeds/${feedId}/runs${query}`)).items,
  });
}

export function useGlobalFeedRuns(status: 'all' | 'success' | 'warning' | 'error') {
  const query = status === 'all' ? '' : `?status=${status}`;
  return useQuery({
    queryKey: ['xmlf', 'feed-runs', 'global', status],
    queryFn: async () => (await jsonFetch<RunsPage>(`/api/feed-runs${query}`)).items,
  });
}

export function useFeedRunLogs(feedId: string | null, runId: string | null) {
  return useQuery({
    queryKey: ['xmlf', 'feed-run-logs', runId ?? 'none'],
    enabled: feedId !== null && runId !== null,
    queryFn: async () =>
      (
        await jsonFetch<{ items: FeedRunLogRow[]; next_cursor: string | null }>(
          `/api/feeds/${feedId}/runs/${runId}/logs?limit=100`,
        )
      ).items,
  });
}

export function formatBytes(bytes: number | null): string {
  if (bytes === null) {
    return '—';
  }
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} kB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatDuration(ms: number | null): string {
  if (ms === null) {
    return '—';
  }
  return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}
