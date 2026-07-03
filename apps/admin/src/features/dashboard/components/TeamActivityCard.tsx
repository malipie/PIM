import { ArrowRight } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { cn } from '@/lib/utils';

import { type ActivityRange, MOST_EDITED, TEAM_ACTIVITY } from '../mocks';

const RANGES: ActivityRange[] = ['7d', '30d', '90d'];
const RANGE_LABELS: Record<ActivityRange, string> = {
  '7d': '7 dni',
  '30d': '30 dni',
  '90d': '90 dni',
};

/**
 * Two-series line chart (added = ink, modified = light blue-gray with a soft
 * area fill), hand-rolled SVG like the previous dashboard chart — no chart
 * dependency for a static mock.
 */
function ActivityLines({ range }: { range: ActivityRange }) {
  const { t } = useTranslation();
  const points = TEAM_ACTIVITY.series[range];
  // 25% headroom above the tallest point so the lines sit mid-chart like
  // the approved mock instead of touching the card edge.
  const max = useMemo(
    () => Math.max(...points.map((point) => Math.max(point.added, point.modified))) * 1.25,
    [points],
  );

  const width = 640;
  const height = 210;
  const padY = 10;
  const innerH = height - padY * 2;

  const coords = (key: 'added' | 'modified') =>
    points.map((point, i) => {
      const x = (i / (points.length - 1)) * width;
      const y = padY + innerH - (point[key] / max) * innerH;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

  const modifiedCoords = coords('modified');
  const areaPath = `M 0,${height} L ${modifiedCoords.join(' L ')} L ${width},${height} Z`;

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
    </svg>
  );
}

/**
 * VIEW-13 (#2143) — "Aktywność katalogu / Tempo pracy zespołu" card:
 * range toggle (cosmetic state only), two-series activity chart and the
 * "Najczęściej edytowane" ranking. All values static mock.
 */
export function TeamActivityCard() {
  const { t } = useTranslation();
  const headingId = useId();
  const [range, setRange] = useState<ActivityRange>('30d');
  const axis = TEAM_ACTIVITY.axis[range];

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
          {RANGES.map((value) => (
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
          <span className="num font-semibold text-ink">{TEAM_ACTIVITY.addedTotal}</span>
        </span>
        <span className="flex items-center gap-2 text-[13px]">
          <span className="size-2 rounded-full bg-[#aebad0]" aria-hidden />
          <span className="text-ink-2">
            {t('dashboard.activity.legend_modified', { defaultValue: 'Zmodyfikowane' })}
          </span>
          <span className="num font-semibold text-ink">{TEAM_ACTIVITY.modifiedTotal}</span>
        </span>
        <span className="ml-auto text-[12px] text-ink-2">
          {t('dashboard.activity.avg_per_day', {
            defaultValue: 'średnio {{count}} zmian / dzień',
            count: TEAM_ACTIVITY.avgPerDay,
          })}
        </span>
      </div>

      <ActivityLines range={range} />
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
        <ul className="mt-2 divide-y divide-line/70">
          {MOST_EDITED.map((product) => (
            <li key={product.sku} className="flex items-center gap-4 py-2.5">
              <div className="min-w-0 flex-1">
                <p className="truncate text-[13.5px] font-medium text-ink">{product.name}</p>
                <p className="mt-0.5 truncate text-[12px] text-ink-2">
                  <span className="font-mono">{product.sku}</span>
                  {' · '}
                  {product.category}
                </p>
              </div>
              <span className="num w-11 shrink-0 text-right text-[13px] text-ink-2">
                {product.completeness}%
              </span>
              <span className="w-[74px] shrink-0 text-right text-[13px]">
                <span className="num font-semibold text-ink">{product.edits}</span>{' '}
                <span className="text-ink-2">
                  {t('dashboard.activity.edits_suffix', { defaultValue: 'edycji' })}
                </span>
              </span>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
