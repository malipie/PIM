import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ProgressBar } from '@/features/imports/primitives';
import { HttpError, jsonFetch } from '@/lib/http';

interface RollbackState {
  status: string;
  objects_done?: number;
  objects_total?: number;
  phase?: string | null;
  stopped_reason?: string | null;
  cancel_requested?: boolean;
}

interface RollbackButtonProps {
  sessionId: string;
  rollbackUntil: string | null;
  /** Session status from the server; `rolling_back` means a run is in flight. */
  status?: string;
  /** Persisted rollback report, so a page refresh mid-undo does not lose it. */
  report?: Record<string, unknown> | null;
  onRolledBack: () => void;
}

/**
 * Spec §5.7 results screen — "Wycofaj import" CTA.
 *
 * #2818 — the rollback is a queued job now, so this is no longer a button that
 * either succeeds or fails within one request. A full catalogue takes minutes:
 * the POST answers 202, the run reports progress, and the operator can stop it.
 * A stopped run is offered a "continue" rather than a fresh rollback, because
 * restarting one would replay an undo-log that is already half spent.
 */
export function RollbackButton({
  sessionId,
  rollbackUntil,
  status,
  report,
  onRolledBack,
}: RollbackButtonProps): React.ReactElement {
  const { t } = useTranslation();
  const [open, setOpen] = React.useState(false);
  const [submitting, setSubmitting] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const isExpired = rollbackUntil === null || new Date(rollbackUntil).getTime() < Date.now();
  const isRollingBack = status === 'rolling_back';
  const done = numberFrom(report?.objects_done);
  const total = numberFrom(report?.objects_total);
  const stopped = report?.phase === 'stopped';
  const cancelRequested = report?.cancel_requested === true;

  const post = (path: string): void => {
    setSubmitting(true);
    setError(null);
    jsonFetch<RollbackState>(`/api/import-sessions/${sessionId}${path}`, { method: 'POST' })
      .then(() => {
        setOpen(false);
        onRolledBack();
      })
      .catch((err: unknown) => {
        if (err instanceof HttpError) {
          setError(`HTTP ${err.status}`);
        } else {
          setError(err instanceof Error ? err.message : 'unknown');
        }
      })
      .finally(() => setSubmitting(false));
  };

  // A run in flight (or one that stopped part-way) replaces the CTA: what the
  // operator needs here is how far it got and a way to stop or continue it.
  if (isRollingBack) {
    return (
      <div className="space-y-2">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium">
            {stopped
              ? t('imports.results.rollback_stopped', {
                  defaultValue: 'Wycofywanie przerwane — niepełne',
                })
              : t('imports.results.rollback_running', { defaultValue: 'Wycofywanie w toku' })}
          </span>
          <span className="text-xs text-muted-foreground num">
            {done.toLocaleString('pl-PL')} / {total.toLocaleString('pl-PL')}
          </span>
        </div>
        <ProgressBar
          value={total > 0 ? Math.min(1, done / total) : 0}
          height={8}
          animated={!stopped}
          ariaLabel={t('imports.results.rollback_progress_aria', {
            defaultValue: 'Postęp wycofywania',
          })}
        />
        {stopped ? (
          <Button
            variant="outline"
            size="sm"
            onClick={() => post('/rollback/resume')}
            disabled={submitting}
          >
            {t('imports.results.rollback_resume', { defaultValue: 'Wznów wycofywanie' })}
          </Button>
        ) : (
          <Button
            variant="outline"
            size="sm"
            onClick={() => post('/rollback/cancel')}
            disabled={submitting || cancelRequested}
          >
            {cancelRequested
              ? t('imports.results.rollback_cancelling', { defaultValue: 'Przerywanie…' })
              : t('imports.results.rollback_cancel', { defaultValue: 'Przerwij wycofywanie' })}
          </Button>
        )}
        {error !== null && (
          <p role="alert" className="text-destructive text-xs">
            {error}
          </p>
        )}
      </div>
    );
  }

  if (isExpired) {
    return (
      <Button variant="outline" disabled>
        ↶ {t('imports.results.rollback_expired', { defaultValue: 'Okno wycofania wygasło' })}
      </Button>
    );
  }

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        ↶ {t('imports.results.rollback', { defaultValue: 'Wycofaj import' })}
      </Button>
      <p className="text-xs text-muted-foreground">
        ⏰{' '}
        {t('imports.results.rollback_window', {
          date: rollbackUntil !== null ? new Date(rollbackUntil).toLocaleString('pl-PL') : '',
          defaultValue: 'Dostępne do {{date}}',
        })}
      </p>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t('imports.results.rollback', { defaultValue: 'Wycofaj import' })}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-3 text-sm">
            <p>
              Operacja usunie wszystkie produkty zaimportowane w tej sesji. Powiązane assety (jeśli
              były pobrane) pozostaną w DAM — usuń je ręcznie.
            </p>
            <p className="rounded-md border border-sky-500/40 bg-sky-50 p-2 text-xs">
              ℹ️ Wycofywanie działa w tle — możesz zamknąć tę stronę. Postęp zobaczysz tutaj i na
              liście sesji, a operację da się przerwać i wznowić.
            </p>
            <p className="rounded-md border border-amber-500/40 bg-amber-50 p-2 text-xs">
              ⚠️ Jeśli te produkty były publikowane do kanałów (Faza 1+) — wycofanie MVP usunie je
              tylko z tej tabeli. Cascade do kanałów dochodzi w Fazie 1.
            </p>
            {error !== null && (
              <p role="alert" className="text-destructive">
                {error}
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
              {t('imports.wizard.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button variant="destructive" onClick={() => post('/rollback')} disabled={submitting}>
              {t('imports.results.rollback', { defaultValue: 'Wycofaj' })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function numberFrom(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}
