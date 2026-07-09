import { Loader2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import type { CatalogRunEvent } from '../api/runs-stream';

/** Status values that end a run — progress stops and the card refreshes. */
const TERMINAL_STATUSES = new Set(['done', 'error', 'cancelled']);

/** True once the stream reports a terminal `status` event. */
export function isTerminalRunEvent(event: CatalogRunEvent | null): boolean {
  return (
    event !== null &&
    event.event === 'status' &&
    typeof event.status === 'string' &&
    TERMINAL_STATUSES.has(event.status)
  );
}

interface CatalogRunProgressProps {
  /** Latest streamed event, or `null` before the first frame arrives. */
  lastEvent: CatalogRunEvent | null;
}

/**
 * CPDF-P5-04 — the live regeneration line on a hub card. A determinate bar when
 * `progress_pct` is known, otherwise an indeterminate "Generowanie…" pill. The
 * caller renders this only while a run is active (non-terminal), so a terminal
 * status collapses it and the card falls back to the refreshed REST state.
 */
export function CatalogRunProgress({ lastEvent }: CatalogRunProgressProps) {
  const { t } = useTranslation();
  const pct =
    lastEvent !== null && typeof lastEvent.progress_pct === 'number'
      ? Math.max(0, Math.min(100, Math.round(lastEvent.progress_pct)))
      : null;

  if (pct === null) {
    return (
      <div
        className="mt-4 flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-[12px] text-zinc-600"
        role="status"
        aria-live="polite"
      >
        <Loader2 className="h-3.5 w-3.5 animate-spin text-zinc-400" aria-hidden />
        {t('catalogs_pdf.card.progress_generating')}
      </div>
    );
  }

  return (
    <div className="mt-4" role="status" aria-live="polite">
      <div className="flex items-center justify-between text-[11.5px] text-zinc-600">
        <span>{t('catalogs_pdf.card.progress_generating')}</span>
        <span className="font-mono tabular-nums">
          {t('catalogs_pdf.card.progress_percent', { pct })}
        </span>
      </div>
      <div
        className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={pct}
        aria-label={t('catalogs_pdf.card.progress_generating')}
      >
        <div
          className="h-full rounded-full bg-zinc-900 transition-[width]"
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}
