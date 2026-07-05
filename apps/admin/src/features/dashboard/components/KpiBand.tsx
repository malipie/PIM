import { ArrowDown, ArrowRight, ArrowUp } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { formatDelta, formatInt, useDashboardSummary } from '../use-dashboard-summary';

export type KpiKey = 'products' | 'publish_ready' | 'avg_completeness' | 'open_alerts';

const TILE_COPY: Record<KpiKey, { label: string; hint: string }> = {
  products: { label: 'Produkty', hint: 'łącznie w katalogu' },
  publish_ready: { label: 'Gotowe do publikacji', hint: '≥ 80% kompletności' },
  avg_completeness: { label: 'Średnia kompletność', hint: 'wszystkie kanały' },
  open_alerts: { label: 'Otwarte alerty', hint: 'wymaga interwencji' },
};

/**
 * DASH-02 (#2251) — per-tile drill-down (brief §5-C: every KPI leads
 * somewhere). Completeness thresholds deep-link through the URL filter
 * seeding added in #2249; the alerts tile is a same-page anchor.
 */
const TILE_HREF: Record<KpiKey, string> = {
  products: '/products',
  publish_ready: '/products?filter[completeness_pct][op]=gte&filter[completeness_pct][value]=80',
  avg_completeness: '/products?filter[completeness_pct][op]=lt&filter[completeness_pct][value]=80',
  open_alerts: '#action-center',
};

interface TileVm {
  key: KpiKey;
  value: string;
  /** Signed delta (null = no trend data — never fabricate one). */
  delta: number | null;
  /** Copy for the null-delta line (alerts tile keeps its "24h" flavour). */
  noTrendLabel: string;
}

/**
 * DASH-06 (#2259) — all four tiles read GET /api/dashboard/summary.
 * Products carry a real created-in-30d delta from day one; the
 * publish-ready and avg-completeness deltas come from the daily
 * snapshots (DASH-05) and stay "brak trendu" until the horizon exists.
 * The alerts tile shows "—" until the alert aggregator lands (DASH-09).
 * Endpoint degraded with no cache ⇒ honest "—" values (brief §8.3),
 * never resurrected mock numbers.
 */
export function KpiBand() {
  const { t } = useTranslation();
  const { summary } = useDashboardSummary();

  const noTrendGeneric = t('dashboard.kpi.no_trend_generic', { defaultValue: 'brak trendu' });
  const noTrendAlerts = t('dashboard.kpi.no_trend', { defaultValue: '24h · brak trendu' });

  const tiles: TileVm[] = [
    {
      key: 'products',
      value: summary === null ? '—' : formatInt(summary.products.total),
      delta: summary?.products.delta30d ?? null,
      noTrendLabel: noTrendGeneric,
    },
    {
      key: 'publish_ready',
      value: summary === null ? '—' : formatInt(summary.publishReady.count),
      delta: summary?.publishReady.delta30d ?? null,
      noTrendLabel: noTrendGeneric,
    },
    {
      key: 'avg_completeness',
      value: summary === null ? '—' : `${summary.avgCompleteness.pct}%`,
      delta: summary?.avgCompleteness.delta30d ?? null,
      noTrendLabel: noTrendGeneric,
    },
    {
      key: 'open_alerts',
      value:
        summary === null || summary.openAlerts === null ? '—' : formatInt(summary.openAlerts.count),
      delta: null,
      noTrendLabel: noTrendAlerts,
    },
  ];

  const tileClass =
    'flex flex-col rounded-2xl border border-line bg-surface p-5 soft-shadow transition-shadow hover:soft-shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink';

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {tiles.map((tile) => (
        // The alerts tile is a same-page anchor — a native <a> scrolls to
        // the target; router Links do not scroll on hash-only navigation.
        <TileLink key={tile.key} href={TILE_HREF[tile.key]} className={tileClass}>
          <span className="text-[13px] font-medium text-ink-2">
            {t(`dashboard.kpi.${tile.key}.label`, { defaultValue: TILE_COPY[tile.key].label })}
          </span>
          <span className="num display mt-2 text-[36px] font-semibold leading-none text-ink">
            {tile.value}
          </span>
          <span className="mt-2.5 flex items-center gap-1 text-[13px]">
            {tile.delta !== null ? (
              <>
                <span
                  className={
                    tile.delta < 0
                      ? 'num inline-flex items-center gap-0.5 font-semibold text-accent-rose'
                      : 'num inline-flex items-center gap-0.5 font-semibold text-emerald-700'
                  }
                >
                  {tile.delta < 0 ? (
                    <ArrowDown className="size-3.5" aria-hidden />
                  ) : (
                    <ArrowUp className="size-3.5" aria-hidden />
                  )}
                  {formatDelta(tile.delta)}
                </span>
                <span className="text-ink-2">
                  · {t('dashboard.kpi.period_30d', { defaultValue: '30 dni' })}
                </span>
              </>
            ) : (
              <span className="text-ink-2">{tile.noTrendLabel}</span>
            )}
          </span>
          <span className="mt-5 flex items-center justify-between text-[12.5px] text-ink-2">
            {t(`dashboard.kpi.${tile.key}.hint`, { defaultValue: TILE_COPY[tile.key].hint })}
            <ArrowRight className="size-4" aria-hidden />
          </span>
        </TileLink>
      ))}
    </div>
  );
}

function TileLink({
  href,
  className,
  children,
}: {
  href: string;
  className: string;
  children: ReactNode;
}) {
  if (href.startsWith('#')) {
    return (
      <a href={href} className={className}>
        {children}
      </a>
    );
  }
  return (
    <Link to={href} className={className}>
      {children}
    </Link>
  );
}
