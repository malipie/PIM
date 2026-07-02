import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { useFeedKpi } from '../api/feeds';
import { type FeedRunRow, useGlobalFeedRuns } from '../api/runs';
import { FeedsSubnav } from '../components/FeedsSubnav';
import { RunDrilldown } from './RunDrilldown';
import { RunsTable } from './RunsTable';

/**
 * XMLF-P5-07 — the global cross-feed monitor (design feed-monitor.jsx
 * FeedMonitor): the four 24h KPI tiles over /api/feed-runs/kpi (+ the P3-06
 * pull counter already surfaced on the hub strip) and the run history of
 * EVERY feed (P4-03 global endpoint) with the status filter and the same
 * drill-down sheet the per-feed detail uses.
 */
export function FeedsMonitorPage() {
  const { t } = useTranslation();
  const kpi = useFeedKpi();
  const [filter, setFilter] = useState<'all' | 'success' | 'warning' | 'error'>('all');
  const runs = useGlobalFeedRuns(filter);
  const [openRun, setOpenRun] = useState<FeedRunRow | null>(null);

  const kpiTiles = [
    {
      key: 'regenerations',
      value: kpi.data?.regenerations24h ?? 0,
      tone: 'text-zinc-900',
    },
    {
      key: 'syndicated',
      value: kpi.data?.itemsSyndicated ?? 0,
      tone: 'text-zinc-900',
    },
    { key: 'skipped', value: kpi.data?.skipped24h ?? 0, tone: 'text-amber-700' },
    { key: 'errors', value: kpi.data?.errors24h ?? 0, tone: 'text-rose-700' },
  ] as const;

  return (
    <div className="space-y-5">
      <FeedsSubnav active="monitor" />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {kpiTiles.map((tile) => (
          <div key={tile.key} className="rounded-2xl bg-white p-4 soft-shadow">
            <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              {t(`api_configurator.feeds.monitor.kpi.${tile.key}`)}
            </div>
            <div className={cn('num mt-1 font-display text-[24px] font-semibold', tile.tone)}>
              {tile.value.toLocaleString('pl-PL')}
            </div>
          </div>
        ))}
      </div>

      <div className="overflow-hidden rounded-3xl bg-white soft-shadow">
        <div className="flex flex-wrap items-center gap-3 border-b border-zinc-100 px-5 py-3">
          <div className="text-[14px] font-semibold tracking-tight">
            {t('api_configurator.feeds.monitor.history_title')}
          </div>
          <div className="ml-auto flex items-center gap-0.5 rounded-xl bg-zinc-100 p-1">
            {(['all', 'success', 'warning', 'error'] as const).map((value) => (
              <button
                key={value}
                type="button"
                aria-pressed={filter === value}
                onClick={() => setFilter(value)}
                className={cn(
                  'h-7 rounded-lg px-2.5 text-[11.5px] font-medium',
                  filter === value ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-white',
                )}
              >
                {t(`api_configurator.feeds.run.filter.${value}`)}
              </button>
            ))}
          </div>
        </div>
        <RunsTable runs={runs.data ?? []} showFeed onOpen={setOpenRun} />
      </div>

      <RunDrilldown run={openRun} onClose={() => setOpenRun(null)} />
    </div>
  );
}
