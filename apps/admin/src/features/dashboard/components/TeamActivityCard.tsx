import { ArrowRight } from 'lucide-react';
import { useId, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';

import { cn } from '@/lib/utils';

import {
  ACTIVITY_RANGES,
  type ActivityRange,
  type DashboardActivityDto,
  isActivityRange,
  useDashboardActivity,
  useDashboardTopEdited,
} from '../use-dashboard-activity';
import { formatInt } from '../use-dashboard-summary';

const RANGE_LABELS: Record<ActivityRange, string> = {
  '7d': '7 dni',
  '30d': '30 dni',
  '90d': '90 dni',
};

const RANGE_DAYS: Record<ActivityRange, number> = { '7d': 7, '30d': 30, '90d': 90 };

/**
 * Two-series line chart (added = ink, modified = light blue-gray with a soft
 * area fill), hand-rolled SVG like the previous dashboard chart — no chart
 * dependency. Renders a flat baseline while the series is degraded/empty.
 */
function ActivityLines({ series }: { series: DashboardActivityDto['series'] }) {
  const { t } = useTranslation();
  // 25% headroom above the tallest point so the lines sit mid-chart like
  // the approved mock instead of touching the card edge; min 1 so an
  // all-zero series stays a flat baseline instead of dividing by zero.
  const max = useMemo(
    () => Math.max(1, ...series.map((point) => Math.max(point.added, point.modified))) * 1.25,
    [series],
  );

  const width = 640;
  const height = 210;
  const padY = 10;
  const innerH = height - padY * 2;
  const lastIndex = Math.max(1, series.length - 1);

  const coords = (key: 'added' | 'modified') =>
    series.map((point, i) => {
      const x = (i / lastIndex) * width;
      const y = padY + innerH - (point[key] / max) * innerH;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

  const modifiedCoords = coords('modified');
  const areaPath =
    series.length > 0 ? `M 0,${height} L ${modifiedCoords.join(' L ')} L ${width},${height} Z` : '';

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      className="mt-4 w-full"
      role="img"
      aria-label={t('dashboard.activity.chart_aria', {
        defaultValue: 'Wykres dziennych zmian w katalogu — dodane i zmodyfikowane produkty',
      })}
      preserveAspectRatio="none"
    >
      {[0.25, 0.5, 0.75].map((fraction) => (
        <line
          key={fraction}
          x1={0}
          x2={width}
          y1={padY + innerH * fraction}
          y2={padY + innerH * fraction}
          stroke="#eef1f6"
          strokeWidth={1}
        />
      ))}
      {series.length > 0 ? (
        <>
          <path d={areaPath} fill="#e9edf4" opacity={0.55} />
          <polyline
            points={modifiedCoords.join(' ')}
            fill="none"
            stroke="#aebad0"
            strokeWidth={1.75}
            strokeLinejoin="round"
          />
          <polyline
            points={coords('added').join(' ')}
            fill="none"
            stroke="var(--ink)"
            strokeWidth={2}
            strokeLinejoin="round"
          />
        </>
      ) : null}
    </svg>
  );
}

/**
 * VIEW-13 (#2143) / DASH-08 (#2263) — "Aktywność katalogu / Tempo pracy
 * zespołu" card, live on GET /api/dashboard/activity + /top-edited: the
 * 7/30/90d toggle persists in the URL (`?range=`, shareable deep-link),
 * the chart renders the real gap-filled series, and every most-edited row
 * links to its product page. Degraded endpoint ⇒ "—" totals and a flat
 * baseline; an empty ranking keeps the section with an honest empty state
 * (brief §5-F / §8.3).
 */
export function TeamActivityCard() {
  const { t } = useTranslation();
  const headingId = useId();
  const [searchParams, setSearchParams] = useSearchParams();

  const rangeParam = searchParams.get('range');
  const range: ActivityRange = isActivityRange(rangeParam) ? rangeParam : '30d';

  const activity = useDashboardActivity(range).data ?? null;
  const topEdited = useDashboardTopEdited(range).data ?? null;

  const setRange = (next: ActivityRange): void => {
    const params = new URLSearchParams(searchParams);
    if (next === '30d') {
      params.delete('range');
    } else {
      params.set('range', next);
    }
    setSearchParams(params, { replace: true });
  };

  const days = RANGE_DAYS[range];
  const axisDaysAgo = (count: number): string =>
    t('dashboard.activity.axis_days_ago', { defaultValue: '{{count}} dni temu', count });
  const axis: [string, string, string] = [
    axisDaysAgo(days - 1),
    axisDaysAgo(Math.floor((days - 1) / 2)),
    t('dashboard.activity.axis_today', { defaultValue: 'dziś' }),
  ];

  return (
    <section
      aria-labelledby={headingId}
      className="flex flex-col rounded-2xl border border-line bg-surface p-6 soft-shadow"
    >
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-[12.5px] text-ink-2">
            {t('dashboard.activity.eyebrow', { defaultValue: 'Aktywność katalogu' })}
          </p>
          <h2 id={headingId} className="display mt-0.5 text-[20px] font-semibold text-ink">
            {t('dashboard.activity.title', { defaultValue: 'Tempo pracy zespołu' })}
          </h2>
        </div>
        <div className="inline-flex shrink-0 items-center rounded-lg border border-line bg-surface-muted p-0.5">
          {ACTIVITY_RANGES.map((value) => (
            <button
              key={value}
              type="button"
              aria-pressed={range === value}
              onClick={() => setRange(value)}
              className={cn(
                'rounded-md px-3 py-1 text-[12.5px] transition-colors',
                range === value
                  ? 'bg-surface font-medium text-ink shadow-sm'
                  : 'text-ink-2 hover:text-ink',
              )}
            >
              {t(`dashboard.activity.range_${value}`, { defaultValue: RANGE_LABELS[value] })}
            </button>
          ))}
        </div>
      </div>

      <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-1.5">
        <span className="flex items-center gap-2 text-[13px]">
          <span className="size-2 rounded-full bg-ink" aria-hidden />
          <span className="text-ink-2">
            {t('dashboard.activity.legend_added', { defaultValue: 'Dodane' })}
          </span>
          <span className="num font-semibold text-ink">
            {activity === null ? '—' : formatInt(activity.addedTotal)}
          </span>
        </span>
        <span className="flex items-center gap-2 text-[13px]">
          <span className="size-2 rounded-full bg-[#aebad0]" aria-hidden />
          <span className="text-ink-2">
            {t('dashboard.activity.legend_modified', { defaultValue: 'Zmodyfikowane' })}
          </span>
          <span className="num font-semibold text-ink">
            {activity === null ? '—' : formatInt(activity.modifiedTotal)}
          </span>
        </span>
        {activity !== null ? (
          <span className="ml-auto text-[12px] text-ink-2">
            {t('dashboard.activity.avg_per_day', {
              defaultValue: 'średnio {{count}} zmian / dzień',
              count: activity.avgPerDay,
            })}
          </span>
        ) : null}
      </div>

      <ActivityLines series={activity?.series ?? []} />
      <div className="mt-1.5 flex items-center justify-between text-[11px] text-ink-2" aria-hidden>
        <span>{axis[0]}</span>
        <span>{axis[1]}</span>
        <span>{axis[2]}</span>
      </div>

      <div className="mt-6 border-t border-line pt-5">
        <div className="flex items-center justify-between">
          <h3 className="text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-2">
            {t('dashboard.activity.most_edited', { defaultValue: 'Najczęściej edytowane' })}
          </h3>
          <Link
            to="/products"
            className="inline-flex items-center gap-1 text-[12.5px] font-medium text-ink hover:underline"
          >
            {t('dashboard.activity.full_list', { defaultValue: 'Pełna lista' })}
            <ArrowRight className="size-3.5" aria-hidden />
          </Link>
        </div>
        {topEdited === null || topEdited.items.length === 0 ? (
          <p className="mt-3 text-[13px] text-ink-2">
            {t('dashboard.activity.most_edited_empty', {
              defaultValue: 'Brak edycji produktów w tym okresie.',
            })}
          </p>
        ) : (
          <ul className="mt-2 divide-y divide-line/70">
            {topEdited.items.map((product) => (
              <li key={product.id}>
                <Link
                  to={`/products/${product.id}`}
                  className="flex items-center gap-4 rounded py-2.5 hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13.5px] font-medium text-ink">{product.name}</p>
                    <p className="mt-0.5 truncate font-mono text-[12px] text-ink-2">
                      {product.sku}
                    </p>
                  </div>
                  <span className="num w-11 shrink-0 text-right text-[13px] text-ink-2">
                    {product.completenessPct}%
                  </span>
                  <span className="w-[74px] shrink-0 text-right text-[13px]">
                    <span className="num font-semibold text-ink">{product.edits}</span>{' '}
                    <span className="text-ink-2">
                      {t('dashboard.activity.edits_suffix', { defaultValue: 'edycji' })}
                    </span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
