import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

/**
 * Provenance source for a single attribute value (#61 / 0.6.8).
 *
 * Mirrors the backend `ObjectValue.provenance` enum (sekcja 5
 * architektury). The `agent` variant is LIVE since epik 0.7 (AGENT-P6-04/05):
 * full-contrast purple, same treatment as the other provenances.
 */
export type Provenance = 'manual' | 'import' | 'integration' | 'agent';

interface ProvenanceBadgeProps {
  provenance: Provenance;
  source?: string | null;
  occurredAt?: string | null;
  className?: string;
}

export function ProvenanceBadge({
  provenance,
  source,
  occurredAt,
  className,
}: ProvenanceBadgeProps) {
  const { t, i18n } = useTranslation();
  const tone = TONES[provenance];
  const label = t(`provenance.${provenance}`, { defaultValue: provenance });
  const tooltip = buildTooltip(t, provenance, source, occurredAt, i18n.language);

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide',
        tone,
        className,
      )}
      title={tooltip}
    >
      {label}
    </span>
  );
}

const TONES: Record<Provenance, string> = {
  manual: 'bg-slate-100 text-slate-700',
  import: 'bg-blue-100 text-blue-900',
  integration: 'bg-orange-100 text-orange-900',
  agent: 'bg-purple-100 text-purple-900',
};

function buildTooltip(
  t: (key: string, options?: Record<string, unknown>) => string,
  provenance: Provenance,
  source: string | null | undefined,
  occurredAt: string | null | undefined,
  locale: string,
): string {
  const parts = [t(`provenance.${provenance}`, { defaultValue: provenance })];
  if (source) {
    parts.push(`${t('provenance.source', { defaultValue: 'Source' })}: ${source}`);
  }
  if (occurredAt) {
    const formatted = formatDate(occurredAt, locale);
    if (formatted !== '') {
      parts.push(`${t('provenance.timestamp', { defaultValue: 'Updated' })}: ${formatted}`);
    }
  }
  return parts.join(' · ');
}

function formatDate(value: string, locale: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
