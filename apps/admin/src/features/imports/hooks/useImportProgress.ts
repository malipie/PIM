import * as React from 'react';

import { useIdentity } from '@/lib/identity';
import { ensureMercureAuthorization, mercureSubscribeUrl, mercureTenantTopic } from '@/lib/mercure';

interface ProgressEvent {
  type: 'progress' | 'error' | 'completed';
  session_id: string;
  data: {
    processed_rows?: number;
    total_rows?: number;
    success_count?: number;
    error_count?: number;
    current_sku?: string | null;
    status?: string;
    // IMP2-2.6 — `error` events carry the offending row's sku + message.
    sku?: string | null;
    row_number?: number;
    message?: string;
  };
}

export interface ImportLogEntry {
  id: number;
  level: 'info' | 'error';
  message: string;
}

const LOG_BUFFER_CAP = 200;

export interface ImportProgressState {
  /** True until the first SSE event lands or the connection drops. */
  connecting: boolean;
  /** NUI-11 / IMP2-2.6 — capped live-log buffer built from `error` + throttled `progress` events. */
  log: ImportLogEntry[];
  processedRows: number;
  totalRows: number;
  successCount: number;
  errorCount: number;
  currentSku: string | null;
  status:
    | 'pending'
    | 'running'
    | 'paused'
    | 'success'
    | 'partial'
    | 'failed'
    | 'cancelled'
    | 'rolling_back'
    | 'rolled_back';
}

const INITIAL_STATE: ImportProgressState = {
  connecting: true,
  log: [],
  processedRows: 0,
  totalRows: 0,
  successCount: 0,
  errorCount: 0,
  currentSku: null,
  status: 'pending',
};

/**
 * Mercure SSE subscriber for the import detail topic
 * (`imports/{session_id}`). Mirrors the IMP-04 publisher. The hook
 * is idempotent — re-subscribing on session id change closes the
 * previous EventSource and opens a fresh one.
 */
export function useImportProgress(sessionId: string | null): ImportProgressState {
  const [state, setState] = React.useState<ImportProgressState>(INITIAL_STATE);
  const { identity } = useIdentity();
  const tenantId = identity?.tenant?.id ?? null;

  React.useEffect(() => {
    if (sessionId === null || tenantId === null) {
      setState(INITIAL_STATE);
      return;
    }
    if (typeof window === 'undefined' || typeof EventSource === 'undefined') {
      return;
    }

    // AUD-001 (#1573) — tenant-scoped, private topic; the SPA must hold a
    // mercureAuthorization cookie (minted below) before the hub accepts the
    // subscription. Topic mirrors MercureSubscribeTopics::importSession().
    const topic = mercureTenantTopic(tenantId, 'imports', sessionId);
    const url = mercureSubscribeUrl(topic);

    let source: EventSource | null = null;
    let cancelled = false;

    let logSeq = 0;
    const attach = (es: EventSource): void => {
      es.addEventListener('open', () => {
        setState((prev) => ({ ...prev, connecting: false }));
      });
      es.addEventListener('message', (event) => {
        try {
          const raw = JSON.parse(event.data) as ProgressEvent;
          setState((prev) => {
            let log = prev.log;
            // IMP2-2.6 — the per-row `row_processed` event is gone (it was 50k hub
            // POSTs on a 50k import). The live log is built from blocking `error`
            // events and the throttled `progress` snapshots (≤ ~100 for 50k rows).
            if (raw.type === 'error') {
              const label = raw.data.sku ?? `row ${raw.data.row_number ?? '?'}`;
              log = [
                ...prev.log.slice(-(LOG_BUFFER_CAP - 1)),
                {
                  id: logSeq++,
                  level: 'error',
                  message: `✗ ${label} — ${raw.data.message ?? 'error'}`,
                },
              ];
            } else if (raw.type === 'progress') {
              const sku = raw.data.current_sku ? ` — ${raw.data.current_sku}` : '';
              log = [
                ...prev.log.slice(-(LOG_BUFFER_CAP - 1)),
                {
                  id: logSeq++,
                  level: 'info',
                  message: `✓ ${raw.data.processed_rows ?? '?'} / ${raw.data.total_rows ?? '?'}${sku}`,
                },
              ];
            }
            return {
              ...prev,
              connecting: false,
              log,
              processedRows: raw.data.processed_rows ?? prev.processedRows,
              totalRows: raw.data.total_rows ?? prev.totalRows,
              successCount: raw.data.success_count ?? prev.successCount,
              errorCount: raw.data.error_count ?? prev.errorCount,
              currentSku: raw.data.current_sku ?? prev.currentSku,
              status: (raw.data.status as ImportProgressState['status']) ?? prev.status,
            };
          });
        } catch {
          // Malformed Mercure payload — surface a console.warn on the
          // root error handler in dev only.
        }
      });

      es.addEventListener('error', () => {
        setState((prev) => ({ ...prev, connecting: false }));
      });
    };

    // Mint the tenant-scoped subscribe cookie first; the hub rejects the
    // EventSource without it (AUD-001). On a mint failure we stop
    // "connecting" so the UI falls back to the REST snapshot.
    ensureMercureAuthorization()
      .then(() => {
        if (cancelled) {
          return;
        }
        source = new EventSource(url, { withCredentials: true });
        attach(source);
      })
      .catch(() => {
        if (!cancelled) {
          setState((prev) => ({ ...prev, connecting: false }));
        }
      });

    return () => {
      cancelled = true;
      source?.close();
    };
  }, [sessionId, tenantId]);

  return state;
}
