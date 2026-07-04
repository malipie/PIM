import { ArrowRight, ArrowUp } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { KPI_TILES, type KpiKey } from '../mocks';
import { formatInt, useDashboardSummary } from '../use-dashboard-summary';

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
  /** Signed delta text; null = no trend data (never fabricate one). */
  delta: string | null;
  /** Copy for the null-delta line (alerts tile keeps its "24h" flavour). */
  noTrendLabel: string;
}

/**
 * VIEW-13 (#2143) / DASH-02 (#2251) — KPI band. Products and
 * publish-ready values are live (existing count hooks via the summary
 * façade) and degrade to the approved mock values per widget; their
 * deltas render as "brak trendu" until the daily snapshot aggregates
 * exist (DASH-05) — NUI-02 rule: never fabricate a trend. The
 * avg-completeness and alerts tiles stay on mock values until their
 * backends land (DASH-06 / DASH-10).
 */
export function KpiBand() {
  const { t } = useTranslation();
  const { productsTotal, completeness } = useDashboardSummary();

  const noTrendGeneric = t('dashboard.kpi.no_trend_generic', { defaultValue: 'brak trendu' });
  const noTrendAlerts = t('dashboard.kpi.no_trend', { defaultValue: '24h · brak trendu' });

  const tiles: TileVm[] = KPI_TILES.map((mock) => {
    switch (mock.key) {
      case 'products':
        return productsTotal === null
          ? { ...mock, noTrendLabel: noTrendGeneric }
          : {
              key: mock.key,
              value: formatInt(productsTotal),
              delta: null,
              noTrendLabel: noTrendGeneric,
            };
      case 'publish_ready':
        return completeness === null
          ? { ...mock, noTrendLabel: noTrendGeneric }
          : {
              key: mock.key,
              value: formatInt(completeness.publishReady),
              delta: null,
              noTrendLabel: noTrendGeneric,
            };
      default:
        return { ...mock, noTrendLabel: noTrendAlerts };
    }
  });

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
                <span className="num inline-flex items-center gap-0.5 font-semibold text-emerald-700">
                  <ArrowUp className="size-3.5" aria-hidden />
                  {tile.delta}
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
