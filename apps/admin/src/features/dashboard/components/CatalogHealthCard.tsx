import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { CATALOG_HEALTH } from '../mocks';
import type { DashboardCompleteness } from '../use-dashboard-completeness';
import { formatInt, useDashboardSummary } from '../use-dashboard-summary';

interface BucketVm {
  label: string;
  count: number;
  /** Decorative segment color (bar + legend dot + ring). */
  color: string;
  /** Products-list drill-down with the matching completeness filter (#2249). */
  href: string;
}

interface HealthVm {
  readyPercent: number;
  readyCount: string;
  totalLine: string;
  /** null = no weekly aggregate yet → the trend badge is not rendered. */
  weeklyDeltaPoints: number | null;
  buckets: BucketVm[];
}

const filterHref = (expr: string): string => `/products?${expr}`;

/**
 * DASH-02 (#2251) — the five distribution buckets in mock order, each with
 * its drill-down filter. Counts come from either the live cumulative
 * aggregate or the mock.
 */
const BUCKET_DEFS: ReadonlyArray<Omit<BucketVm, 'count'>> = [
  {
    label: '100%',
    color: '#15803d',
    href: filterHref('filter[completeness_pct][op]=gte&filter[completeness_pct][value]=100'),
  },
  {
    label: '80–99%',
    color: '#22c55e',
    href: filterHref('filter[completeness_pct][op]=between&filter[completeness_pct][value]=80,99'),
  },
  {
    label: '50–79%',
    color: '#f97316',
    href: filterHref('filter[completeness_pct][op]=between&filter[completeness_pct][value]=50,79'),
  },
  {
    label: '25–49%',
    color: '#ef4444',
    href: filterHref('filter[completeness_pct][op]=between&filter[completeness_pct][value]=25,49'),
  },
  {
    label: '< 25%',
    color: '#cbd5e1',
    href: filterHref('filter[completeness_pct][op]=lt&filter[completeness_pct][value]=25'),
  },
];

/** Cumulative "at least N%" counts → disjoint bucket counts, mock order. */
function disjointCounts(live: DashboardCompleteness): number[] {
  const at = (gte: number): number => live.buckets.find((b) => b.gte === gte)?.count ?? 0;
  return [
    at(100),
    Math.max(0, at(80) - at(100)),
    Math.max(0, at(50) - at(80)),
    Math.max(0, at(25) - at(50)),
    Math.max(0, live.total - at(25)),
  ];
}

function buildVm(live: DashboardCompleteness | null): HealthVm {
  if (live === null) {
    // Endpoint degraded → approved mock values, per-widget (brief §8.3).
    return {
      readyPercent: CATALOG_HEALTH.readyPercent,
      readyCount: CATALOG_HEALTH.readyCount,
      totalLine: CATALOG_HEALTH.totalLine,
      weeklyDeltaPoints: CATALOG_HEALTH.weeklyDeltaPoints,
      buckets: BUCKET_DEFS.map((def, i) => ({
        ...def,
        count: CATALOG_HEALTH.buckets[i]?.count ?? 0,
      })),
    };
  }
  const counts = disjointCounts(live);
  return {
    readyPercent: live.publishReadyPct,
    readyCount: formatInt(live.publishReady),
    totalLine: `/ ${formatInt(live.total)} SKU ≥ 80%`,
    // No weekly aggregate exists yet (DASH-05 snapshots) — hide the badge
    // instead of decorating live data with the mock trend.
    weeklyDeltaPoints: null,
    buckets: BUCKET_DEFS.map((def, i) => ({ ...def, count: counts[i] ?? 0 })),
  };
}

/**
 * Donut ring: publish-ready share in ink, the sub-threshold buckets as
 * colored slivers (50–79 orange, 25–49 red, <25 light gray) — matching the
 * approved mock. Purely decorative; the numbers live in the legend.
 */
function ReadyRing({ percent, buckets }: { percent: number; buckets: BucketVm[] }) {
  const { t } = useTranslation();
  const size = 176;
  const stroke = 15;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;

  const total = buckets.reduce((sum, bucket) => sum + bucket.count, 0);
  const belowThreshold = buckets.slice(2); // 50–79 / 25–49 / <25
  const segments = [
    { share: percent / 100, color: 'var(--ink)' },
    ...belowThreshold.map((bucket) => ({
      share: total > 0 ? bucket.count / total : 0,
      color: bucket.color,
    })),
  ];

  let offset = 0;
  const arcs = segments.map((segment) => {
    const dash = segment.share * c;
    const arc = { ...segment, dash, offset };
    offset += dash;
    return arc;
  });

  return (
    <div className="relative shrink-0" style={{ width: size, height: size }}>
      <svg
        viewBox={`0 0 ${size} ${size}`}
        className="size-full -rotate-90"
        role="img"
        aria-label={t('dashboard.health.ring_aria', {
          defaultValue: '{{percent}}% produktów gotowych do publikacji',
          percent,
        })}
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="#e9edf4"
          strokeWidth={stroke}
        />
        {arcs.map((arc) => (
          <circle
            key={arc.color}
            cx={size / 2}
            cy={size / 2}
            r={r}
            fill="none"
            stroke={arc.color}
            strokeWidth={stroke}
            strokeDasharray={`${arc.dash} ${c - arc.dash}`}
            strokeDashoffset={-arc.offset}
          />
        ))}
      </svg>
      <div className="absolute inset-0 grid place-items-center">
        <div className="text-center">
          <span className="num display block text-[34px] font-semibold leading-none text-ink">
            {percent}%
          </span>
          <span className="mx-auto mt-1 block max-w-[90px] text-[11px] leading-tight text-ink-2">
            {t('dashboard.health.ring_caption', { defaultValue: 'gotowe do publikacji' })}
          </span>
        </div>
      </div>
    </div>
  );
}

/**
 * VIEW-13 (#2143) / DASH-02 (#2251) — "Zdrowie danych / Kompletność
 * katalogu" card. Ring, ready count and the distribution buckets are live
 * (completeness aggregate via the summary façade) and degrade to the
 * approved mock per widget; every bucket legend row drills down to the
 * products list with the matching filter. Per-channel completeness stays
 * mock until its aggregate endpoint lands (DASH-03/DASH-06); the weekly
 * trend badge renders only when trend data exists (mock path) — never a
 * fabricated trend on live data.
 */
export function CatalogHealthCard() {
  const { t } = useTranslation();
  const headingId = useId();
  const { completeness } = useDashboardSummary();
  const vm = buildVm(completeness);
  const total = vm.buckets.reduce((sum, bucket) => sum + bucket.count, 0);

  return (
    <section
      aria-labelledby={headingId}
      className="rounded-2xl border border-line bg-surface p-6 soft-shadow"
    >
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-[12.5px] text-ink-2">
            {t('dashboard.health.eyebrow', { defaultValue: 'Zdrowie danych' })}
          </p>
          <h2 id={headingId} className="display mt-0.5 text-[20px] font-semibold text-ink">
            {t('dashboard.health.title', { defaultValue: 'Kompletność katalogu' })}
          </h2>
        </div>
        {vm.weeklyDeltaPoints !== null ? (
          <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[12px] font-medium text-emerald-700">
            <span className="size-1.5 rounded-full bg-emerald-600" aria-hidden />
            {t('dashboard.health.weekly_trend', {
              defaultValue: '+ {{count}} pkt / tydz.',
              count: vm.weeklyDeltaPoints,
            })}
          </span>
        ) : null}
      </div>

      <div className="mt-6 flex flex-col items-center gap-6 sm:flex-row">
        <ReadyRing percent={vm.readyPercent} buckets={vm.buckets} />
        <div className="min-w-0 flex-1">
          <p className="flex flex-wrap items-baseline gap-x-2">
            <span className="num display text-[30px] font-semibold leading-none text-ink">
              {vm.readyCount}
            </span>
            <span className="num text-[13px] text-ink-2">{vm.totalLine}</span>
          </p>
          <div className="mt-3 flex h-2.5 w-full gap-px overflow-hidden rounded-full" aria-hidden>
            {vm.buckets.map((bucket) => (
              <span
                key={bucket.label}
                className="h-full"
                style={{
                  width: `${total > 0 ? (bucket.count / total) * 100 : 0}%`,
                  backgroundColor: bucket.color,
                }}
              />
            ))}
          </div>
          <ul className="mt-4 grid grid-cols-2 gap-x-6 gap-y-2">
            {vm.buckets.map((bucket) => (
              <li key={bucket.label}>
                <Link
                  to={bucket.href}
                  className="flex items-center gap-2 rounded text-[13px] hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
                  aria-label={t('dashboard.health.bucket_link_aria', {
                    defaultValue: 'Pokaż produkty o kompletności {{bucket}}',
                    bucket: bucket.label,
                  })}
                >
                  <span
                    className="size-2 shrink-0 rounded-full"
                    style={{ backgroundColor: bucket.color }}
                    aria-hidden
                  />
                  <span className="num text-ink-2">{bucket.label}</span>
                  {/* Mock renders raw digits (4210) — no grouping below 5 digits. */}
                  <span className="num ml-auto font-medium text-ink">{bucket.count}</span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <div className="mt-6 border-t border-line pt-5">
        <div className="flex items-center justify-between">
          <h3 className="text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-2">
            {t('dashboard.health.channels_header', { defaultValue: 'Kompletność wg kanału' })}
          </h3>
          <span className="text-[12px] text-ink-2">
            {t('dashboard.health.channels_sort', { defaultValue: 'sort: najgorszy pierwszy' })}
          </span>
        </div>
        <ul className="mt-4 space-y-3.5">
          {CATALOG_HEALTH.channels.map((channel) => (
            <li key={channel.name} className="flex items-center gap-4">
              <span className="w-32 shrink-0 truncate text-[13.5px] text-ink sm:w-36">
                {channel.name}
              </span>
              <span
                className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2"
                aria-hidden
              >
                <span
                  className="block h-full rounded-full"
                  style={{ width: `${channel.percent}%`, backgroundColor: channel.color }}
                />
              </span>
              <span className="num w-10 shrink-0 text-right text-[13.5px] font-semibold text-ink">
                {channel.percent}%
              </span>
              <span className="num w-14 shrink-0 text-right text-[12.5px] text-ink-2">
                {channel.count}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
