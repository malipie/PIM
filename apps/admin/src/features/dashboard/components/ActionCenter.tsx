import { AlertTriangle, ArrowRight, CheckCircle2 } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { toast } from '@/components/ui/toast';
import { cn } from '@/lib/utils';

import {
  type AlertSeverity,
  type DashboardAlertItem,
  useAckAlert,
  useDashboardAlerts,
} from '../use-dashboard-alerts';

const SEVERITY_STYLE: Record<AlertSeverity, { box: string; icon: string; word: string }> = {
  critical: { box: 'bg-red-50', icon: 'text-accent-rose', word: 'text-accent-rose' },
  warning: { box: 'bg-amber-50', icon: 'text-accent-amber', word: 'text-accent-amber' },
};

/** Per-type CTA target + label key. A missing context id falls back to the hub. */
function ctaFor(item: DashboardAlertItem): { href: string; labelKey: string } {
  const ctx = item.context;
  switch (item.type) {
    case 'sync_run':
      return {
        href: ctx.connectionId
          ? `/integrations/api-configurator/connections/${ctx.connectionId}/sync`
          : '/integrations/api-configurator/monitor',
        labelKey: 'view_log',
      };
    case 'import_session':
      return { href: `/integrations/imports/${ctx.sessionId ?? ''}`, labelKey: 'download_report' };
    case 'feed_run':
      return {
        href: `/integrations/api-configurator/feeds/${ctx.feedProfileId ?? ''}`,
        labelKey: 'open_feed',
      };
    case 'completeness_drop':
      return {
        href: '/products?filter[completeness_pct][op]=lt&filter[completeness_pct][value]=80',
        labelKey: 'show_products',
      };
    case 'webhook':
      return { href: '/integrations/api-configurator?tab=webhooks', labelKey: 'open_log' };
  }
}

/**
 * Compose the human title from the alert's structured params (the BE
 * returns fields, not strings, so pl/en stay in parity). Each type has a
 * dedicated interpolated key; the raw params double as the fallback.
 */
function useAlertText(item: DashboardAlertItem) {
  const { t } = useTranslation();
  const p = item.params;
  const num = (key: string): number => (typeof p[key] === 'number' ? (p[key] as number) : 0);
  const str = (key: string): string => (typeof p[key] === 'string' ? (p[key] as string) : '');

  const title = t(`dashboard.action_center.types.${item.type}`, {
    defaultValue: str('sourceName'),
    sourceName: str('sourceName'),
    failedCount: num('failedCount'),
    errorCount: num('errorCount'),
    reason: str('reason'),
    eventType: str('eventType'),
    deliveries: num('deliveries'),
    httpStatus: num('httpStatus'),
    avgPct: num('avgPct'),
    previousPct: num('previousPct'),
    threshold: num('threshold'),
  });

  const source = t(`dashboard.action_center.sources.${item.type}`, {
    defaultValue: item.type,
  });

  return { title, source };
}

function ActionItem({
  item,
  onAck,
  acking,
}: {
  item: DashboardAlertItem;
  onAck: (fingerprint: string) => void;
  acking: boolean;
}) {
  const { t } = useTranslation();
  const style = SEVERITY_STYLE[item.severity];
  const { title, source } = useAlertText(item);
  const cta = ctaFor(item);
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
        <p className="text-[13.5px] font-semibold leading-snug text-ink">{title}</p>
        <p className="mt-1 text-[12px] leading-snug">
          <span className={cn('font-medium', style.word)}>{severityWord}</span>
          <span className="text-ink-2">
            {' · '}
            {source}
            {' · '}
            {item.occurredAt}
          </span>
        </p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1.5">
        <Link
          to={cta.href}
          className="rounded-lg border border-line bg-surface px-3 py-1.5 text-[12.5px] font-medium text-ink transition-colors hover:bg-surface-muted"
        >
          {t(`dashboard.action_center.cta.${cta.labelKey}`, { defaultValue: cta.labelKey })}
        </Link>
        <button
          type="button"
          disabled={acking}
          onClick={() => onAck(item.fingerprint)}
          className="text-[11px] text-ink-2 hover:underline disabled:opacity-50"
        >
          {t('dashboard.action_center.mark_read', { defaultValue: 'oznacz jako przeczytane' })}
        </button>
      </div>
    </li>
  );
}

/**
 * VIEW-13 (#2143) / DASH-10 (#2273) — full-width "Wymaga uwagi / Centrum
 * akcji", live on GET /api/dashboard/alerts: severity counters, per-type
 * CTAs into the source views, and optimistic acknowledge. "Pokaż
 * wszystkie" expands the feed in place (30d/50); an empty feed shows the
 * positive "Wszystko działa ✓" state (brief §5-B — never hide the
 * section).
 */
export function ActionCenter() {
  const { t } = useTranslation();
  const headingId = useId();
  const [expanded, setExpanded] = useState(false);

  const window = expanded ? '30d' : '7d';
  const limit = expanded ? 50 : 6;
  const { data } = useDashboardAlerts(window, limit);
  const ack = useAckAlert(window, limit);

  const handleAck = (fingerprint: string): void => {
    ack.mutate(fingerprint, {
      onError: () =>
        toast.error(
          t('dashboard.action_center.ack_failed', {
            defaultValue: 'Nie udało się oznaczyć alertu.',
          }),
        ),
    });
  };

  const items = data?.items ?? [];
  const total = data?.total ?? 0;
  const critical = data?.critical ?? 0;
  const warnings = data?.warnings ?? 0;
  const allCount = data?.allCount ?? 0;

  return (
    // id — anchor target for the KPI alerts tile drill-down (DASH-02 #2251);
    // scroll-mt keeps the heading below the sticky topbar after the jump.
    <section
      id="action-center"
      aria-labelledby={headingId}
      className="scroll-mt-24 rounded-2xl border border-line bg-surface p-6 soft-shadow"
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
                count: total,
              })}
            </span>
          </div>
        </div>
        {total > 0 ? (
          <div className="flex flex-wrap items-center gap-2.5">
            <span className="num rounded-full bg-accent-rose/10 px-2.5 py-1 text-[12px] font-medium text-accent-rose">
              {t('dashboard.action_center.critical_count', {
                defaultValue: '{{count}} krytyczne',
                count: critical,
              })}
            </span>
            <span className="num rounded-full bg-accent-amber/10 px-2.5 py-1 text-[12px] font-medium text-accent-amber">
              {t('dashboard.action_center.warning_count', {
                defaultValue: '{{count}} ostrzeżenia',
                count: warnings,
              })}
            </span>
            {!expanded && allCount > items.length ? (
              <button
                type="button"
                onClick={() => setExpanded(true)}
                className="inline-flex items-center gap-1 text-[13px] font-medium text-ink hover:underline"
              >
                {t('dashboard.action_center.show_all', {
                  defaultValue: 'Pokaż wszystkie ( {{count}} )',
                  count: allCount,
                })}
                <ArrowRight className="size-3.5" aria-hidden />
              </button>
            ) : null}
          </div>
        ) : null}
      </div>

      {items.length === 0 ? (
        <div className="mt-5 flex items-center gap-2.5 rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 text-[13.5px] font-medium text-emerald-700">
          <CheckCircle2 className="size-4.5 shrink-0" aria-hidden />
          {t('dashboard.action_center.empty_ok', { defaultValue: 'Wszystko działa ✓' })}
        </div>
      ) : (
        <ul className="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
          {items.map((item) => (
            <ActionItem
              key={item.fingerprint}
              item={item}
              onAck={handleAck}
              acking={ack.isPending}
            />
          ))}
        </ul>
      )}
    </section>
  );
}
