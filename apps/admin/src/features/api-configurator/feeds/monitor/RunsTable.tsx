import { ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import { type FeedRunRow, formatBytes, formatDuration, type RunHealth } from '../api/runs';

const DOT_TONES: Record<RunHealth, string> = {
  success: 'bg-emerald-500',
  warning: 'bg-amber-400',
  error: 'bg-rose-500',
  pending: 'bg-zinc-300',
  running: 'bg-sky-500 animate-pulse',
  cancelled: 'bg-zinc-400',
};

export function HealthDot({ health }: { health: RunHealth }) {
  return <span className={cn('h-2 w-2 shrink-0 rounded-full', DOT_TONES[health])} aria-hidden />;
}

export function TriggerBadge({ trigger }: { trigger: string }) {
  const { t } = useTranslation();
  return (
    <span
      className={cn(
        'rounded px-1.5 py-0.5 font-mono text-[10px] uppercase',
        trigger === 'schedule' ? 'bg-sky-50 text-sky-700' : 'bg-zinc-100 text-zinc-600',
      )}
    >
      {t(`api_configurator.feeds.run.trigger.${trigger}`, { defaultValue: trigger })}
    </span>
  );
}

export function RunStatusPill({ health }: { health: RunHealth }) {
  const { t } = useTranslation();
  const tone =
    health === 'success'
      ? 'bg-emerald-50 text-emerald-700'
      : health === 'warning'
        ? 'bg-amber-50 text-amber-800'
        : health === 'error'
          ? 'bg-rose-50 text-rose-700'
          : health === 'running'
            ? 'bg-sky-50 text-sky-700'
            : 'bg-zinc-100 text-zinc-600';
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-medium',
        tone,
      )}
    >
      <HealthDot health={health} />
      {t(`api_configurator.feeds.run.health.${health}`)}
    </span>
  );
}

/**
 * XMLF-P5-05 — the run history table (design feed-monitor.jsx RunsTable):
 * one row per FeedRun, clickable into the drill-down sheet. `showFeed`
 * switches between the global monitor (feed identity column) and the
 * per-feed history on the detail screen.
 */
export function RunsTable({
  runs,
  showFeed,
  onOpen,
}: {
  runs: FeedRunRow[];
  showFeed: boolean;
  onOpen: (run: FeedRunRow) => void;
}) {
  const { t } = useTranslation();

  if (runs.length === 0) {
    return (
      <p className="px-5 py-6 text-center text-[12.5px] text-zinc-500">
        {t('api_configurator.feeds.run.empty')}
      </p>
    );
  }

  return (
    <div>
      <div
        className={cn(
          'grid gap-3 border-b border-zinc-100 px-5 py-2 text-[10.5px] font-medium uppercase tracking-wider text-zinc-500',
          showFeed
            ? 'grid-cols-[2fr_0.8fr_0.7fr_1fr_0.7fr_0.9fr_24px]'
            : 'grid-cols-[1.4fr_0.8fr_0.7fr_1fr_0.7fr_0.9fr_24px]',
        )}
      >
        <div>
          {t(
            showFeed
              ? 'api_configurator.feeds.run.col.feed_start'
              : 'api_configurator.feeds.run.col.start',
          )}
        </div>
        <div>{t('api_configurator.feeds.run.col.trigger')}</div>
        <div>{t('api_configurator.feeds.run.col.duration')}</div>
        <div>{t('api_configurator.feeds.run.col.items')}</div>
        <div>{t('api_configurator.feeds.run.col.size')}</div>
        <div>{t('api_configurator.feeds.run.col.status')}</div>
        <div />
      </div>
      {runs.map((run) => (
        <button
          key={run.id}
          type="button"
          onClick={() => onOpen(run)}
          className={cn(
            'grid w-full items-center gap-3 border-b border-zinc-50 px-5 py-2.5 text-left text-[12.5px] transition hover:bg-zinc-50/70',
            showFeed
              ? 'grid-cols-[2fr_0.8fr_0.7fr_1fr_0.7fr_0.9fr_24px]'
              : 'grid-cols-[1.4fr_0.8fr_0.7fr_1fr_0.7fr_0.9fr_24px]',
          )}
        >
          <div className="flex min-w-0 items-center gap-2">
            <HealthDot health={run.health} />
            <div className="min-w-0">
              {showFeed && (
                <div className="truncate font-medium text-zinc-800">
                  {run.feed_name ?? run.feed_code ?? run.feed_id}
                </div>
              )}
              <div className={cn('text-zinc-500', showFeed ? 'text-[11px]' : 'text-zinc-700')}>
                {new Date(run.started_at).toLocaleString('pl-PL')}
              </div>
            </div>
          </div>
          <div>
            <TriggerBadge trigger={run.trigger} />
          </div>
          <div className="num text-zinc-600">{formatDuration(run.duration_ms)}</div>
          <div className="num text-zinc-800">
            {run.item_count.toLocaleString('pl-PL')}
            {run.skipped_count > 0 && (
              <span className="ml-1 text-[11px] text-amber-700">⤼ {run.skipped_count}</span>
            )}
          </div>
          <div className="num text-zinc-600">{formatBytes(run.file_size_bytes)}</div>
          <div>
            <RunStatusPill health={run.health} />
          </div>
          <ChevronRight className="h-3.5 w-3.5 text-zinc-300" aria-hidden />
        </button>
      ))}
    </div>
  );
}
