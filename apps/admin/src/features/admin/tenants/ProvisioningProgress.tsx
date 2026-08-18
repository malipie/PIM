import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { jsonFetch } from '@/lib/http';

import type { ProvisioningStatus } from './types';

/**
 * TNT-P4-06 (#2907) — postęp zakładania instancji.
 *
 * Zakładanie klienta trwa kilkadziesiąt sekund i bywa przerywane, więc
 * operator musi widzieć, na czym stoi, a przy porażce — **co** padło.
 * „Coś poszło nie tak" nie nadaje się do pokazania człowiekowi: provisioner
 * raportuje kroki i to je pokazujemy.
 */

const TERMINAL: ReadonlyArray<ProvisioningStatus['state']> = ['done', 'failed', 'rejected'];
const POLL_MS = 2500;
/** Zabezpieczenie przed odpytywaniem bez końca, gdy provisioner nie żyje. */
const MAX_POLLS = 240;

interface Props {
  jobId: string;
  onFinished?: (state: ProvisioningStatus['state']) => void;
}

export function ProvisioningProgress({ jobId, onFinished }: Props) {
  const { t } = useTranslation();
  const [status, setStatus] = useState<ProvisioningStatus | null>(null);
  const [pollsExhausted, setPollsExhausted] = useState(false);
  const finishedRef = useRef(false);

  useEffect(() => {
    let cancelled = false;
    let polls = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const tick = async () => {
      if (cancelled) return;

      try {
        const next = await jsonFetch<ProvisioningStatus>(
          `/api/admin/tenants/provisioning/${jobId}`,
          { accept: 'application/json' },
        );
        if (cancelled) return;
        setStatus(next);

        if (TERMINAL.includes(next.state)) {
          if (!finishedRef.current) {
            finishedRef.current = true;
            onFinished?.(next.state);
          }
          return;
        }
      } catch {
        // Pojedyncze nieudane odpytanie nie oznacza porażki provisioningu —
        // instancja może właśnie przeładowywać kontenery. Próbujemy dalej,
        // aż do limitu.
      }

      polls += 1;
      if (polls >= MAX_POLLS) {
        setPollsExhausted(true);
        return;
      }
      timer = setTimeout(() => void tick(), POLL_MS);
    };

    void tick();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [jobId, onFinished]);

  const state = status?.state ?? 'queued';
  const steps = status?.steps ?? [];

  return (
    <div className="space-y-3" data-testid="provisioning-progress">
      <p className="text-sm text-muted-foreground">
        {state === 'queued' && t('admin.tenants.provisioning.queued')}
        {state === 'running' && t('admin.tenants.provisioning.running')}
        {state === 'done' && t('admin.tenants.provisioning.done')}
        {(state === 'failed' || state === 'rejected') && t('admin.tenants.provisioning.failed')}
      </p>

      <ol className="space-y-1.5" aria-live="polite">
        {steps.map((step) => (
          <li key={step.step} className="flex items-center gap-2 text-sm">
            {step.ok ? (
              <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
            ) : (
              <AlertCircle className="size-4 text-destructive" aria-hidden="true" />
            )}
            <span className="font-mono text-xs">{step.step}</span>
          </li>
        ))}
        {!TERMINAL.includes(state) && (
          <li className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
            <span>{t('admin.tenants.provisioning.in_progress')}</span>
          </li>
        )}
      </ol>

      {/* Powód porażki pokazujemy dosłownie — operator ma wiedzieć, co
          poprawić, a nie zgadywać. */}
      {(state === 'failed' || state === 'rejected') && (status?.error || steps.length > 0) && (
        <pre className="max-h-40 overflow-auto rounded-md border bg-muted/30 p-2 text-[11px] whitespace-pre-wrap">
          {status?.error ??
            steps
              .filter((s) => !s.ok)
              .map((s) => s.detail ?? s.step)
              .join('\n')}
        </pre>
      )}

      {pollsExhausted && !TERMINAL.includes(state) && (
        <p className="text-xs text-destructive">{t('admin.tenants.provisioning.timeout')}</p>
      )}
    </div>
  );
}
