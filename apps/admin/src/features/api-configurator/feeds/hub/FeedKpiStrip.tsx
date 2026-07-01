import { Rss } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Sparkline } from '@/components/ui-v2/sparkline';

import type { FeedKpi } from '../api/feeds';

/**
 * XMLF-P5-01 — the hub KPI strip (design feed-primitives.jsx FeedKpiStrip):
 * feed status split, current syndication snapshot and the 24h pull counter
 * with its sparkline (XMLF-P3-06 telemetry).
 */
export function FeedKpiStrip({ kpi }: { kpi: FeedKpi | undefined }) {
  const { t } = useTranslation();
  const total = kpi ? kpi.active + kpi.paused + kpi.error : 0;

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
      <div className="rounded-2xl bg-white p-4 soft-shadow">
        <div className="flex items-start justify-between">
          <div>
            <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              {t('api_configurator.feeds.kpi.feeds')}
            </div>
            <div className="num mt-1 font-display text-[28px] font-semibold tracking-tight">
              {total}
            </div>
            <div className="mt-1.5 flex items-center gap-2 text-[11px]">
              <span className="num flex items-center gap-1 font-medium text-emerald-700">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden />
                {kpi?.active ?? 0}
              </span>
              <span className="num flex items-center gap-1 font-medium text-amber-700">
                <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden />
                {kpi?.paused ?? 0}
              </span>
              <span className="num flex items-center gap-1 font-medium text-rose-700">
                <span className="h-1.5 w-1.5 rounded-full bg-rose-500" aria-hidden />
                {kpi?.error ?? 0}
              </span>
            </div>
          </div>
          <div className="grid h-10 w-10 place-items-center rounded-xl bg-zinc-900 text-white">
            <Rss className="h-4.5 w-4.5" aria-hidden />
          </div>
        </div>
      </div>

      <div className="rounded-2xl bg-white p-4 soft-shadow">
        <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
          {t('api_configurator.feeds.kpi.items')}
        </div>
        <div className="num mt-1 font-display text-[28px] font-semibold tracking-tight">
          {(kpi?.itemsSyndicated ?? 0).toLocaleString('pl-PL')}
        </div>
        <div className="mt-1.5 text-[11px] text-zinc-500">
          {t('api_configurator.feeds.kpi.items_hint')}
        </div>
      </div>

      <div className="rounded-2xl bg-white p-4 soft-shadow">
        <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
          {t('api_configurator.feeds.kpi.pulls')}
        </div>
        <div className="num mt-1 font-display text-[28px] font-semibold tracking-tight">
          {(kpi?.pulls24h ?? 0).toLocaleString('pl-PL')}
        </div>
        <div className="-ml-0.5 mt-1">
          <Sparkline
            data={(kpi?.spark ?? []).map((point) => point.count)}
            width={130}
            height={22}
          />
        </div>
      </div>
    </div>
  );
}
