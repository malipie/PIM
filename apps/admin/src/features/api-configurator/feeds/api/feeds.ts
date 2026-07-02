import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/**
 * XMLF-P5-01 — data layer for the feeds hub. `/api/feeds` is a custom
 * `#[Route]` surface (not an API Platform resource), so the Refine
 * dataProvider's hydra parsing does not apply — plain jsonFetch + react-query,
 * the established pattern for custom endpoints.
 */

export type FeedStatus = 'active' | 'paused' | 'error';
export type FeedTemplateKind = 'google_shopping' | 'ceneo' | 'meta' | 'custom';

export interface FeedRow {
  id: string;
  code: string;
  name: string;
  template_kind: FeedTemplateKind;
  status: FeedStatus;
  locale: string | null;
  currency: string | null;
  channel_id: string | null;
  publication_channel: string | null;
  schedule_cron: string | null;
  descriptor: Record<string, unknown>;
  field_mappings: Array<Record<string, unknown>>;
  filter?: unknown;
  validation_policy?: 'skip_invalid' | 'include_with_warning';
  cached_item_count: number | null;
  cached_at: string | null;
  last_pulled_at: string | null;
  has_token: boolean;
}

export interface FeedKpi {
  active: number;
  paused: number;
  error: number;
  itemsSyndicated: number;
  pulls24h: number;
  spark: Array<{ bucket: string; count: number }>;
}

interface FeedsResponse {
  items: FeedRow[];
}

interface RunsKpiResponse {
  items_syndicated: number;
  feeds: { active: number; paused: number; error: number };
}

interface PullStatsResponse {
  pulls_24h: number;
  spark: Array<{ bucket: string; count: number }>;
}

export const FEEDS_QUERY_KEY = ['xmlf', 'feeds'] as const;

export async function fetchFeed(id: string): Promise<FeedRow> {
  return jsonFetch<FeedRow>(`/api/feeds/${id}`);
}

/** Command helpers for the wizard (POST create / PATCH update). */
export async function createFeed(payload: Record<string, unknown>): Promise<FeedRow> {
  return jsonFetch<FeedRow>('/api/feeds', { method: 'POST', body: payload });
}

export async function patchFeed(id: string, payload: Record<string, unknown>): Promise<FeedRow> {
  return jsonFetch<FeedRow>(`/api/feeds/${id}`, { method: 'PATCH', body: payload });
}

export function useFeeds() {
  return useQuery({
    queryKey: FEEDS_QUERY_KEY,
    queryFn: async () => (await jsonFetch<FeedsResponse>('/api/feeds')).items,
  });
}

/**
 * The design's FEED_KPI merges two read models: feed status counts + current
 * syndication snapshot (`/api/feed-runs/kpi`, XMLF-P4-03) and the pull counter
 * + sparkline (`/api/feeds/pull-stats`, XMLF-P3-06). The `/api/feeds/stats`
 * endpoint sketched in the backlog never existed — these two cover it.
 */
export function useFeedKpi() {
  return useQuery({
    queryKey: ['xmlf', 'feed-kpi'],
    queryFn: async (): Promise<FeedKpi> => {
      const [kpi, pulls] = await Promise.all([
        jsonFetch<RunsKpiResponse>('/api/feed-runs/kpi'),
        jsonFetch<PullStatsResponse>('/api/feeds/pull-stats'),
      ]);
      return {
        active: kpi.feeds.active,
        paused: kpi.feeds.paused,
        error: kpi.feeds.error,
        itemsSyndicated: kpi.items_syndicated,
        pulls24h: pulls.pulls_24h,
        spark: pulls.spark,
      };
    },
  });
}

function useFeedAction(path: (id: string) => string, method: 'POST' | 'DELETE' = 'POST') {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) => jsonFetch<Record<string, unknown>>(path(id), { method }),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: FEEDS_QUERY_KEY });
      void queryClient.invalidateQueries({ queryKey: ['xmlf', 'feed-kpi'] });
    },
  });
}

export function useRegenerateFeed() {
  return useFeedAction((id) => `/api/feeds/${id}/regenerate`);
}

export function usePauseFeed() {
  return useFeedAction((id) => `/api/feeds/${id}/pause`);
}

export function useResumeFeed() {
  return useFeedAction((id) => `/api/feeds/${id}/resume`);
}

export interface MintedToken {
  token: string;
  url: string;
}

/** Mint/rotate the URL token — the plaintext URL is shown once (P3-04/P3-05). */
export function useRotateToken() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) =>
      jsonFetch<MintedToken>(`/api/feeds/${id}/token`, { method: 'POST' }),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: FEEDS_QUERY_KEY });
    },
  });
}

/**
 * Mapping coverage for the card's CoverageBar — client-side mirror of the
 * health endpoint's math: slots come from the descriptor (flat custom shape
 * `item.slots` or the marketplace RSS shape `channel.item.slots`), mapped =
 * slots with a mapping entry.
 */
export function computeCoverage(
  descriptor: Record<string, unknown>,
  mappings: Array<Record<string, unknown>>,
): { mapped: number; total: number } {
  const item = pickRecord(descriptor.item) ?? pickRecord(pickRecord(descriptor.channel)?.item);
  const slots = Array.isArray(item?.slots) ? item.slots : [];
  const targets = new Set<string>();
  for (const slot of slots) {
    const record = pickRecord(slot);
    const target = record?.slot ?? record?.target;
    if (typeof target === 'string' && target !== '') {
      targets.add(target);
    }
  }
  let mapped = 0;
  const seen = new Set<string>();
  for (const mapping of mappings) {
    const slot = mapping.slot;
    if (typeof slot === 'string' && targets.has(slot) && !seen.has(slot)) {
      seen.add(slot);
      mapped += 1;
    }
  }
  return { mapped, total: targets.size };
}

function pickRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;
}

/** Search (name|code) + status filter — the hub toolbar semantics. */
export function filterFeeds(
  feeds: FeedRow[],
  search: string,
  status: 'all' | FeedStatus,
): FeedRow[] {
  const needle = search.trim().toLowerCase();
  return feeds.filter((feed) => {
    if (status !== 'all' && feed.status !== status) {
      return false;
    }
    if (
      needle !== '' &&
      !feed.name.toLowerCase().includes(needle) &&
      !feed.code.toLowerCase().includes(needle)
    ) {
      return false;
    }
    return true;
  });
}
