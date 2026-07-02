import { AlertTriangle, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

import { type FeedRunRow, formatBytes, formatDuration, useFeedRunLogs } from '../api/runs';
import { HealthDot, RunStatusPill, TriggerBadge } from './RunsTable';

/**
 * XMLF-P5-05 — the run drill-down sheet (design feed-monitor.jsx
 * FeedRunDrilldown): run identity + metric tiles + the per-product
 * FeedRunLog table ("SKU-123 · g:gtin — missing required g:gtin — skipped")
 * from GET /api/feeds/{id}/runs/{runId}/logs. CSV export / feed-file
 * download land with their backend endpoints (deliberate hooks).
 */
export function RunDrilldown({
  run,
  onClose,
  onRegenerate,
}: {
  run: FeedRunRow | null;
  onClose: () => void;
  onRegenerate?: (feedId: string) => void;
}) {
  const { t } = useTranslation();
  const logs = useFeedRunLogs(run?.feed_id ?? null, run?.id ?? null);

  return (
    <Sheet open={run !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side="right"
        closeLabel={t('api_configurator.feeds.run.close')}
        className="w-full overflow-y-auto p-5 sm:max-w-xl"
      >
        {run !== null && (
          <>
            <div className="space-y-1">
              <SheetTitle className="flex flex-wrap items-center gap-2 text-[15px]">
                {t('api_configurator.feeds.run.drill_title')}
                <RunStatusPill health={run.health} />
                <TriggerBadge trigger={run.trigger} />
              </SheetTitle>
              <p className="text-[12px] text-zinc-500">
                {new Date(run.started_at).toLocaleString('pl-PL')} ·{' '}
                {formatDuration(run.duration_ms)} · {formatBytes(run.file_size_bytes)}
              </p>
            </div>

            {run.error_message !== null && (
              <div className="mt-3 flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50/70 px-3 py-2 text-[12px] text-rose-900">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" aria-hidden />
                {run.error_message}
              </div>
            )}

            <div className="mt-4 grid grid-cols-4 gap-2 text-center">
              {(
                [
                  ['in_feed', run.item_count, 'text-zinc-900'],
                  ['skipped', run.skipped_count, 'text-amber-700'],
                  ['warnings', run.warning_count, 'text-zinc-700'],
                  ['size', formatBytes(run.file_size_bytes), 'text-zinc-700'],
                ] as const
              ).map(([key, value, tone]) => (
                <div key={key} className="rounded-xl bg-zinc-50 px-2 py-2">
                  <div className={cn('num text-[15px] font-semibold', tone)}>
                    {typeof value === 'number' ? value.toLocaleString('pl-PL') : value}
                  </div>
                  <div className="text-[9.5px] uppercase tracking-wider text-zinc-500">
                    {t(`api_configurator.feeds.run.metric.${key}`)}
                  </div>
                </div>
              ))}
            </div>

            <div className="mt-5">
              <div className="mb-2 text-[12px] font-semibold uppercase tracking-wider text-zinc-500">
                {t('api_configurator.feeds.log.title')}
              </div>
              {logs.isLoading ? (
                <div className="h-24 animate-pulse rounded-xl bg-zinc-100" />
              ) : (logs.data ?? []).length === 0 ? (
                <p className="text-[12.5px] text-emerald-700">
                  {t('api_configurator.feeds.log.empty')}
                </p>
              ) : (
                <ul className="space-y-1.5">
                  {(logs.data ?? []).map((line) => (
                    <li key={line.id} className="flex items-start gap-2 text-[12px]">
                      <HealthDot
                        health={
                          line.level === 'error'
                            ? 'error'
                            : line.level === 'warning'
                              ? 'warning'
                              : 'success'
                        }
                      />
                      <span className="min-w-0">
                        <span className="font-mono text-[11px] text-zinc-500">
                          {line.object_sku ?? '—'}
                          {line.slot !== null && ` · ${line.slot}`}
                        </span>{' '}
                        <span className="text-zinc-700">{line.message}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            {onRegenerate !== undefined && (
              <div className="mt-5 border-t border-zinc-100 pt-4">
                <button
                  type="button"
                  onClick={() => onRegenerate(run.feed_id)}
                  className="flex h-9 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 text-[12px] font-medium text-zinc-700 hover:bg-zinc-50"
                >
                  <RefreshCw className="h-3.5 w-3.5" aria-hidden />
                  {t('api_configurator.feeds.run.regenerate_again')}
                </button>
              </div>
            )}
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
