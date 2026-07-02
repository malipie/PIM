import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import type { FeedTemplateKind } from '../api/feeds';

/**
 * XMLF-P5-01 — feed-area primitives ported from the design's
 * feed-primitives.jsx (TemplateBadge / CoverageBar / CopyButton) onto the
 * project's shadcn/Tailwind conventions.
 */

const TEMPLATE_TONES: Record<FeedTemplateKind, string> = {
  google_shopping: 'border-sky-300 text-sky-700',
  ceneo: 'border-emerald-300 text-emerald-700',
  meta: 'border-violet-300 text-violet-700',
  custom: 'border-zinc-300 text-zinc-600',
};

export function TemplateBadge({ kind }: { kind: FeedTemplateKind }) {
  const { t } = useTranslation();
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md border border-dashed px-1.5 py-0.5 font-mono text-[10.5px]',
        TEMPLATE_TONES[kind] ?? TEMPLATE_TONES.custom,
      )}
    >
      {t(`api_configurator.feeds.template.${kind}`)}
    </span>
  );
}

export function CoverageBar({
  mapped,
  total,
  width = 96,
}: {
  mapped: number;
  total: number;
  width?: number;
}) {
  const { t } = useTranslation();
  const pct = total > 0 ? Math.round((mapped / total) * 100) : 0;
  const tone = pct >= 90 ? 'bg-emerald-500' : pct >= 60 ? 'bg-amber-500' : 'bg-rose-500';
  return (
    <div className="flex items-center gap-2">
      <div
        role="progressbar"
        aria-valuenow={mapped}
        aria-valuemin={0}
        aria-valuemax={total}
        aria-label={t('api_configurator.feeds.card.coverage_aria', { mapped, total })}
        className="h-1.5 overflow-hidden rounded-full bg-zinc-100"
        style={{ width }}
      >
        <div className={cn('h-full rounded-full', tone)} style={{ width: `${pct}%` }} />
      </div>
      <span className="num text-[11.5px] text-zinc-600">
        {mapped}/{total}
      </span>
    </div>
  );
}

export function CopyButton({ value, disabled }: { value: string; disabled?: boolean }) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);

  async function copy(): Promise<void> {
    await navigator.clipboard.writeText(value);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1500);
  }

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => void copy()}
      aria-label={t('api_configurator.feeds.card.copy_url')}
      className={cn(
        'grid h-7 w-7 place-items-center rounded-lg border border-zinc-200 bg-white text-zinc-500 transition',
        disabled ? 'cursor-not-allowed opacity-40' : 'hover:text-zinc-700',
      )}
    >
      {copied ? (
        <Check className="h-3.5 w-3.5 text-emerald-600" />
      ) : (
        <Copy className="h-3.5 w-3.5" />
      )}
    </button>
  );
}
