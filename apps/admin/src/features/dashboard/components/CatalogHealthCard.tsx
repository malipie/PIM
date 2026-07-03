import { useId } from 'react';
import { useTranslation } from 'react-i18next';

import { CATALOG_HEALTH } from '../mocks';

/**
 * Donut ring: publish-ready share in ink, the sub-threshold buckets as
 * colored slivers (50–79 orange, 25–49 red, <25 light gray) — matching the
 * approved mock. Purely decorative; the numbers live in the legend.
 */
function ReadyRing({ percent }: { percent: number }) {
  const { t } = useTranslation();
  const size = 176;
  const stroke = 15;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;

  const total = CATALOG_HEALTH.buckets.reduce((sum, bucket) => sum + bucket.count, 0);
  const belowThreshold = CATALOG_HEALTH.buckets.slice(2); // 50–79 / 25–49 / <25
  const segments = [
    { share: percent / 100, color: 'var(--ink)' },
    ...belowThreshold.map((bucket) => ({ share: bucket.count / total, color: bucket.color })),
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
 * VIEW-13 (#2143) — "Zdrowie danych / Kompletność katalogu" card: ready
 * ring, completeness distribution (stacked bar + legend) and per-channel
 * completeness sorted worst-first. All values static mock.
 */
export function CatalogHealthCard() {
  const { t } = useTranslation();
  const headingId = useId();
  const total = CATALOG_HEALTH.buckets.reduce((sum, bucket) => sum + bucket.count, 0);

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
        <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[12px] font-medium text-emerald-700">
          <span className="size-1.5 rounded-full bg-emerald-600" aria-hidden />
          {t('dashboard.health.weekly_trend', {
            defaultValue: '+ {{count}} pkt / tydz.',
            count: CATALOG_HEALTH.weeklyDeltaPoints,
          })}
        </span>
      </div>

      <div className="mt-6 flex flex-col items-center gap-6 sm:flex-row">
        <ReadyRing percent={CATALOG_HEALTH.readyPercent} />
        <div className="min-w-0 flex-1">
          <p className="flex flex-wrap items-baseline gap-x-2">
            <span className="num display text-[30px] font-semibold leading-none text-ink">
              {CATALOG_HEALTH.readyCount}
            </span>
            <span className="num text-[13px] text-ink-2">{CATALOG_HEALTH.totalLine}</span>
          </p>
          <div className="mt-3 flex h-2.5 w-full gap-px overflow-hidden rounded-full" aria-hidden>
            {CATALOG_HEALTH.buckets.map((bucket) => (
              <span
                key={bucket.label}
                className="h-full"
                style={{
                  width: `${(bucket.count / total) * 100}%`,
                  backgroundColor: bucket.color,
                }}
              />
            ))}
          </div>
          <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-2">
            {CATALOG_HEALTH.buckets.map((bucket) => (
              <div key={bucket.label} className="flex items-center gap-2 text-[13px]">
                <span
                  className="size-2 shrink-0 rounded-full"
                  style={{ backgroundColor: bucket.color }}
                  aria-hidden
                />
                <dt className="num text-ink-2">{bucket.label}</dt>
                {/* Mock renders raw digits (4210) — no grouping below 5 digits. */}
                <dd className="num ml-auto font-medium text-ink">{bucket.count}</dd>
              </div>
            ))}
          </dl>
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
