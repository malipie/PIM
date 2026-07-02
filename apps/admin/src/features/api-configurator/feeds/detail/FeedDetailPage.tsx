import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowLeft, Link2, Pencil, RefreshCw, Rss } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { useToast } from '@/components/ui/toast';
import { ConnStatusPill } from '@/features/api-configurator/components/primitives';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

import { computeCoverage, fetchFeed, useRegenerateFeed, useRotateToken } from '../api/feeds';
import { type FeedRunRow, useFeedRuns } from '../api/runs';
import { type FeedRunEvent, useFeedRunsStream } from '../api/runs-stream';
import { CopyButton, CoverageBar, TemplateBadge } from '../components/primitives';
import { RunDrilldown } from '../monitor/RunDrilldown';
import { RunsTable } from '../monitor/RunsTable';

const STAGES = ['query', 'serialize', 'validate', 'store', 'done'] as const;
type Stage = (typeof STAGES)[number];

/**
 * XMLF-P5-05 — the feed detail (design feed-monitor.jsx FeedDetail): header
 * with the live badge, the public-URL row, feed KPI, the live regeneration
 * pipeline driven by the P4-02 Mercure stream (`feeds/{id}/runs`) and the
 * run history with the per-product drill-down. REST is the source of truth;
 * SSE events move the pipeline and trigger refetches.
 */
export function FeedDetailPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  const queryClient = useQueryClient();
  const feedId = id ?? null;

  const feed = useQuery({
    queryKey: ['xmlf', 'feed', feedId ?? 'none'],
    enabled: feedId !== null,
    queryFn: async () => fetchFeed(feedId ?? ''),
  });
  const [runFilter, setRunFilter] = useState<'all' | 'success' | 'warning' | 'error'>('all');
  const runs = useFeedRuns(feedId, runFilter);
  const stream = useFeedRunsStream(feedId);
  const regenerate = useRegenerateFeed();
  const rotate = useRotateToken();
  const [mintedUrl, setMintedUrl] = useState<string | null>(null);
  const [openRun, setOpenRun] = useState<FeedRunRow | null>(null);
  const [liveEvent, setLiveEvent] = useState<FeedRunEvent | null>(null);

  const lastEvent = stream.lastEvent;
  useEffect(() => {
    if (lastEvent === null) {
      return;
    }
    setLiveEvent(lastEvent);
    if (lastEvent.event === 'status' && lastEvent.status !== 'running') {
      // Terminal status — the run history and the feed KPI are stale now.
      void queryClient.invalidateQueries({ queryKey: ['xmlf', 'feed-runs'] });
      void queryClient.invalidateQueries({ queryKey: ['xmlf', 'feed', feedId ?? 'none'] });
    }
  }, [lastEvent, queryClient, feedId]);

  const pipeline = useMemo(() => derivePipeline(liveEvent), [liveEvent]);
  const running = pipeline.stage !== null && pipeline.stage !== 'done';
  const row = feed.data;
  const coverage = useMemo(
    () =>
      row === undefined
        ? { mapped: 0, total: 0 }
        : computeCoverage(row.descriptor, row.field_mappings),
    [row],
  );

  function onRegenerate(): void {
    if (feedId === null) {
      return;
    }
    setLiveEvent(null);
    regenerate.mutate(feedId, {
      onSuccess: () => toast.success(t('api_configurator.feeds.card.regenerate_started')),
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error')),
    });
  }

  function onRotate(): void {
    if (feedId === null) {
      return;
    }
    rotate.mutate(feedId, {
      onSuccess: (minted) => {
        setMintedUrl(
          minted.url.startsWith('http') ? minted.url : `${window.location.origin}${minted.url}`,
        );
        toast.success(t('api_configurator.feeds.card.rotate_success'));
      },
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error')),
    });
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => void navigate('/integrations/api-configurator/feeds')}
          aria-label={t('api_configurator.feeds.wizard.back_to_hub')}
          className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white text-zinc-500 soft-shadow hover:text-zinc-900"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
        </button>
        <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-zinc-100 text-zinc-600">
          <Rss className="h-4.5 w-4.5" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-display text-[20px] font-semibold tracking-tight">
              {row?.name ?? '…'}
            </h1>
            {row !== undefined && <TemplateBadge kind={row.template_kind} />}
            {row !== undefined && (
              <ConnStatusPill
                status={row.status}
                label={t(`api_configurator.feeds.status.${row.status}`)}
              />
            )}
            {stream.connected && running && (
              <span className="inline-flex items-center gap-1.5 rounded-md bg-sky-50 px-2 py-0.5 font-mono text-[10.5px] text-sky-700">
                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500" aria-hidden />
                {t('api_configurator.feeds.detail.live', { topic: `feed-runs.${feedId ?? ''}` })}
              </span>
            )}
          </div>
          {row !== undefined && <p className="font-mono text-[11.5px] text-zinc-500">{row.code}</p>}
        </div>
        <button
          type="button"
          onClick={() => void navigate(`/integrations/api-configurator/feeds/${feedId}/edit`)}
          className="flex h-9 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 text-[12.5px] font-medium text-zinc-700 hover:bg-zinc-50"
        >
          <Pencil className="h-3.5 w-3.5" aria-hidden />
          {t('api_configurator.feeds.detail.edit')}
        </button>
        <button
          type="button"
          onClick={onRegenerate}
          disabled={regenerate.isPending || running}
          className="flex h-9 items-center gap-1.5 rounded-lg bg-zinc-900 px-3 text-[12.5px] font-medium text-white hover:bg-zinc-800 disabled:opacity-40"
        >
          <RefreshCw
            className={cn('h-3.5 w-3.5', (regenerate.isPending || running) && 'animate-spin')}
            aria-hidden
          />
          {t('api_configurator.feeds.detail.regenerate_now')}
        </button>
      </div>

      <div className="flex items-center gap-2 rounded-2xl bg-white px-4 py-3 soft-shadow">
        <Link2 className="h-4 w-4 shrink-0 text-zinc-500" aria-hidden />
        <code className="min-w-0 flex-1 truncate rounded-lg border border-zinc-100 bg-zinc-50 px-2.5 py-1.5 font-mono text-[11.5px] text-zinc-600">
          {mintedUrl ??
            t(
              row?.has_token === true
                ? 'api_configurator.feeds.card.url_masked'
                : 'api_configurator.feeds.card.url_none',
            )}
        </code>
        <CopyButton value={mintedUrl ?? ''} disabled={mintedUrl === null} />
        <button
          type="button"
          onClick={onRotate}
          disabled={rotate.isPending}
          aria-label={t('api_configurator.feeds.card.rotate')}
          className="grid h-8 w-8 place-items-center rounded-lg border border-zinc-200 bg-white text-zinc-500 hover:text-zinc-800 disabled:opacity-40"
        >
          <RefreshCw
            className={cn('h-3.5 w-3.5', rotate.isPending && 'animate-spin')}
            aria-hidden
          />
        </button>
      </div>

      {(running || pipeline.stage === 'done') && (
        <div className="rounded-2xl bg-white px-5 py-4 soft-shadow">
          <div className="mb-3 flex items-center justify-between text-[12px] text-zinc-600">
            <span className="font-medium">{t('api_configurator.feeds.detail.pipeline_title')}</span>
            {pipeline.pct !== null && <span className="num">{pipeline.pct}%</span>}
          </div>
          <ol
            className="flex items-center gap-2"
            aria-label={t('api_configurator.feeds.detail.pipeline_title')}
          >
            {STAGES.map((stage, index) => {
              const activeIndex = pipeline.stage === null ? -1 : STAGES.indexOf(pipeline.stage);
              const state =
                pipeline.stage === 'done'
                  ? 'done'
                  : index < activeIndex
                    ? 'done'
                    : index === activeIndex
                      ? 'active'
                      : 'pending';
              return (
                <li key={stage} className="flex min-w-0 flex-1 items-center gap-2">
                  <div
                    className={cn(
                      'h-1.5 min-w-0 flex-1 rounded-full',
                      state === 'done' && 'bg-emerald-500',
                      state === 'active' && 'animate-pulse bg-sky-500',
                      state === 'pending' && 'bg-zinc-100',
                    )}
                  />
                  <span
                    className={cn(
                      'shrink-0 font-mono text-[10px]',
                      state === 'pending' ? 'text-zinc-400' : 'text-zinc-600',
                    )}
                  >
                    {t(`api_configurator.feeds.detail.stage.${stage}`)}
                  </span>
                </li>
              );
            })}
          </ol>
        </div>
      )}

      {row !== undefined && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div className="rounded-2xl bg-white p-4 soft-shadow">
            <div className="text-[10px] uppercase tracking-wider text-zinc-500">
              {t('api_configurator.feeds.card.items')}
            </div>
            <div className="num mt-1 text-[20px] font-semibold text-zinc-900">
              {row.cached_item_count?.toLocaleString('pl-PL') ?? '—'}
            </div>
          </div>
          <div className="rounded-2xl bg-white p-4 soft-shadow">
            <div className="text-[10px] uppercase tracking-wider text-zinc-500">
              {t('api_configurator.feeds.detail.last_pull')}
            </div>
            <div className="mt-1 text-[13px] text-zinc-700">
              {row.last_pulled_at !== null
                ? new Date(row.last_pulled_at).toLocaleString('pl-PL')
                : '—'}
            </div>
          </div>
          <div className="rounded-2xl bg-white p-4 soft-shadow">
            <div className="text-[10px] uppercase tracking-wider text-zinc-500">
              {t('api_configurator.feeds.card.schedule')}
            </div>
            <div className="mt-1 font-mono text-[13px] text-zinc-700">
              {row.schedule_cron ?? t('api_configurator.feeds.card.schedule_manual')}
            </div>
          </div>
          <div className="rounded-2xl bg-white p-4 soft-shadow">
            <div className="text-[10px] uppercase tracking-wider text-zinc-500">
              {t('api_configurator.feeds.card.coverage')}
            </div>
            <div className="mt-2">
              <CoverageBar mapped={coverage.mapped} total={coverage.total} />
            </div>
          </div>
        </div>
      )}

      {row?.status === 'error' && (
        <div className="flex items-start gap-2 rounded-2xl border border-rose-200 bg-rose-50/70 px-4 py-3 text-[12.5px] text-rose-900">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" aria-hidden />
          {t('api_configurator.feeds.detail.health_note')}
        </div>
      )}

      <div className="overflow-hidden rounded-3xl bg-white soft-shadow">
        <div className="flex flex-wrap items-center gap-3 border-b border-zinc-100 px-5 py-3">
          <div className="text-[14px] font-semibold tracking-tight">
            {t('api_configurator.feeds.detail.history_title')}
          </div>
          <div className="ml-auto flex items-center gap-0.5 rounded-xl bg-zinc-100 p-1">
            {(['all', 'success', 'warning', 'error'] as const).map((value) => (
              <button
                key={value}
                type="button"
                aria-pressed={runFilter === value}
                onClick={() => setRunFilter(value)}
                className={cn(
                  'h-7 rounded-lg px-2.5 text-[11.5px] font-medium',
                  runFilter === value ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-white',
                )}
              >
                {t(`api_configurator.feeds.run.filter.${value}`)}
              </button>
            ))}
          </div>
        </div>
        <RunsTable runs={runs.data ?? []} showFeed={false} onOpen={setOpenRun} />
      </div>

      <RunDrilldown
        run={openRun}
        onClose={() => setOpenRun(null)}
        onRegenerate={() => {
          setOpenRun(null);
          onRegenerate();
        }}
      />
    </div>
  );
}

/**
 * Map the P4-02 event stream onto the design's 5-stage pipeline: `running`
 * lights the query stage, progress events advance serialize/validate with
 * the reported pct, the terminal status lands on done.
 */
function derivePipeline(event: FeedRunEvent | null): { stage: Stage | null; pct: number | null } {
  if (event === null) {
    return { stage: null, pct: null };
  }
  if (event.event === 'status') {
    if (event.status === 'running') {
      return { stage: 'query', pct: null };
    }
    if (event.status === 'done') {
      return { stage: 'done', pct: 100 };
    }
    return { stage: null, pct: null };
  }
  const pct = event.progress_pct ?? null;
  return { stage: pct !== null && pct > 66 ? 'validate' : 'serialize', pct };
}
