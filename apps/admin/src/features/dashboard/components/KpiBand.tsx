import { ArrowRight, ArrowUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { KPI_TILES, type KpiKey } from '../mocks';

const TILE_COPY: Record<KpiKey, { label: string; hint: string }> = {
  products: { label: 'Produkty', hint: 'łącznie w katalogu' },
  publish_ready: { label: 'Gotowe do publikacji', hint: '≥ 80% kompletności' },
  avg_completeness: { label: 'Średnia kompletność', hint: 'wszystkie kanały' },
  open_alerts: { label: 'Otwarte alerty', hint: 'wymaga interwencji' },
};

/**
 * VIEW-13 (#2143) — KPI band, four static tiles per the approved mock.
 * Values and deltas are mock data; the alerts tile deliberately renders
 * "brak trendu" instead of a fabricated arrow (NUI-02 rule). The previous
 * configurable 4-of-8 tile picker was dropped with the redesign — the mock
 * pins exactly these four tiles.
 */
export function KpiBand() {
  const { t } = useTranslation();

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {KPI_TILES.map((tile) => (
        <div
          key={tile.key}
          className="flex flex-col rounded-2xl border border-line bg-surface p-5 soft-shadow"
        >
          <span className="text-[13px] font-medium text-ink-2">
            {t(`dashboard.kpi.${tile.key}.label`, { defaultValue: TILE_COPY[tile.key].label })}
          </span>
          <span className="num display mt-2 text-[36px] font-semibold leading-none text-ink">
            {tile.value}
          </span>
          <span className="mt-2.5 flex items-center gap-1 text-[13px]">
            {tile.delta !== null ? (
              <>
                <span className="num inline-flex items-center gap-0.5 font-semibold text-emerald-700">
                  <ArrowUp className="size-3.5" aria-hidden />
                  {tile.delta}
                </span>
                <span className="text-ink-2">
                  · {t('dashboard.kpi.period_30d', { defaultValue: '30 dni' })}
                </span>
              </>
            ) : (
              <span className="text-ink-2">
                {t('dashboard.kpi.no_trend', { defaultValue: '24h · brak trendu' })}
              </span>
            )}
          </span>
          <span className="mt-5 flex items-center justify-between text-[12.5px] text-ink-2">
            {t(`dashboard.kpi.${tile.key}.hint`, { defaultValue: TILE_COPY[tile.key].hint })}
            <ArrowRight className="size-4" aria-hidden />
          </span>
        </div>
      ))}
    </div>
  );
}
