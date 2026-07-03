import { AlertTriangle, ArrowRight } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import { ACTION_CENTER, type ActionCenterItem, type ActionSeverity } from '../mocks';

const SEVERITY_STYLE: Record<ActionSeverity, { box: string; icon: string; word: string }> = {
  critical: { box: 'bg-red-50', icon: 'text-accent-rose', word: 'text-accent-rose' },
  warning: { box: 'bg-amber-50', icon: 'text-accent-amber', word: 'text-accent-amber' },
};

function ActionItem({ item }: { item: ActionCenterItem }) {
  const { t } = useTranslation();
  const style = SEVERITY_STYLE[item.severity];
  const severityWord =
    item.severity === 'critical'
      ? t('dashboard.action_center.severity_critical', { defaultValue: 'Krytyczny' })
      : t('dashboard.action_center.severity_warning', { defaultValue: 'Ostrzeżenie' });

  return (
    <li className="flex items-start gap-3 rounded-xl border border-line/80 bg-surface p-4">
      <span className={cn('grid size-10 shrink-0 place-items-center rounded-full', style.box)}>
        <AlertTriangle className={cn('size-4', style.icon)} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-[13.5px] font-semibold leading-snug text-ink">{item.title}</p>
        <p className="mt-1 text-[12px] leading-snug">
          <span className={cn('font-medium', style.word)}>{severityWord}</span>
          <span className="text-ink-2">
            {' · '}
            {item.source}
            {' · '}
            {item.when}
          </span>
        </p>
        <p className="mt-0.5 text-[12px] leading-snug text-ink-2">{item.detail}</p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1.5">
        {/* Visual no-op — the drill-down target ships with the alert backend. */}
        <button
          type="button"
          className="rounded-lg border border-line bg-surface px-3 py-1.5 text-[12.5px] font-medium text-ink transition-colors hover:bg-surface-muted"
        >
          {item.cta}
        </button>
        {/* Visual no-op — ack needs the Alert entity (backend backlog). */}
        <button type="button" className="text-[11px] text-ink-2 hover:underline">
          {t('dashboard.action_center.mark_read', { defaultValue: 'oznacz jako przeczytane' })}
        </button>
      </div>
    </li>
  );
}

/**
 * VIEW-13 (#2143) — full-width "Wymaga uwagi / Centrum akcji": five mock
 * items in a two-column grid with severity counters and per-item CTAs.
 * The Alert entity/backend is in the dashboard backlog; every interaction
 * here is a visual no-op.
 */
export function ActionCenter() {
  const { t } = useTranslation();
  const headingId = useId();

  return (
    <section
      aria-labelledby={headingId}
      className="rounded-2xl border border-line bg-surface p-6 soft-shadow"
    >
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
        <div>
          <p className="text-[12.5px] text-ink-2">
            {t('dashboard.action_center.eyebrow', { defaultValue: 'Wymaga uwagi' })}
          </p>
          <div className="mt-0.5 flex items-center gap-2.5">
            <h2 id={headingId} className="display text-[20px] font-semibold text-ink">
              {t('dashboard.action_center.title', { defaultValue: 'Centrum akcji' })}
            </h2>
            <span className="num rounded-full bg-ink px-2.5 py-0.5 text-[11.5px] font-medium text-white">
              {t('dashboard.action_center.cases_count', {
                defaultValue: '{{count}} spraw',
                count: ACTION_CENTER.total,
              })}
            </span>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2.5">
          <span className="num rounded-full bg-accent-rose/10 px-2.5 py-1 text-[12px] font-medium text-accent-rose">
            {t('dashboard.action_center.critical_count', {
              defaultValue: '{{count}} krytyczne',
              count: ACTION_CENTER.critical,
            })}
          </span>
          <span className="num rounded-full bg-accent-amber/10 px-2.5 py-1 text-[12px] font-medium text-accent-amber">
            {t('dashboard.action_center.warning_count', {
              defaultValue: '{{count}} ostrzeżenia',
              count: ACTION_CENTER.warnings,
            })}
          </span>
          {/* Visual no-op — the full alert list ships with the alert backend. */}
          <button
            type="button"
            className="inline-flex items-center gap-1 text-[13px] font-medium text-ink hover:underline"
          >
            {t('dashboard.action_center.show_all', {
              defaultValue: 'Pokaż wszystkie ( {{count}} )',
              count: ACTION_CENTER.allCount,
            })}
            <ArrowRight className="size-3.5" aria-hidden />
          </button>
        </div>
      </div>
      <ul className="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
        {ACTION_CENTER.items.map((item) => (
          <ActionItem key={item.id} item={item} />
        ))}
      </ul>
    </section>
  );
}
