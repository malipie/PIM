import * as React from 'react';

import { useIdentity } from '@/lib/identity';
import { ensureMercureAuthorization, mercureSubscribeUrl, mercureTenantTopic } from '@/lib/mercure';

/**
 * CPDF-P5-04 — SSE subscriber for one catalog's run stream (P4 publisher).
 * Split from api/catalogs.ts so the ADR-0021 transport-in-effect guard stays
 * clean: this file owns the EventSource effect and carries no HTTP transport at
 * all (mirrors the feeds api/runs-stream.ts split).
 */

export interface CatalogRunEvent {
  event: 'progress' | 'status';
  run_id: string;
  catalog_id: string;
  items_done?: number;
  items_total?: number | null;
  progress_pct?: number | null;
  estimated_seconds_remaining?: number | null;
  status?: string;
  error_message?: string | null;
}

export interface CatalogRunsStream {
  connected: boolean;
  lastEvent: CatalogRunEvent | null;
  topic: string | null;
}

/**
 * SSE subscriber for one catalog's run stream. Mirrors the
 * useFeedRunsStream pattern: mint the mercureAuthorization cookie, open the
 * EventSource, surface the latest event; no-ops without EventSource and on hub
 * errors (the REST catalogs/kpi refresh stays the fallback). Pass `null` while
 * no run is active so idle hub cards never open a socket.
 */
export function useCatalogRunsStream(catalogId: string | null): CatalogRunsStream {
  const [connected, setConnected] = React.useState(false);
  const [lastEvent, setLastEvent] = React.useState<CatalogRunEvent | null>(null);
  const { identity } = useIdentity();
  const tenantId = identity?.tenant?.id ?? null;
  const topic =
    catalogId !== null && tenantId !== null
      ? mercureTenantTopic(tenantId, 'catalogs', catalogId, 'runs')
      : null;

  React.useEffect(() => {
    if (topic === null) {
      setConnected(false);
      setLastEvent(null);
      return;
    }
    if (typeof window === 'undefined' || typeof EventSource === 'undefined') {
      return;
    }

    const url = mercureSubscribeUrl(topic);
    let source: EventSource | null = null;
    let cancelled = false;

    ensureMercureAuthorization()
      .then(() => {
        if (cancelled) {
          return;
        }
        source = new EventSource(url, { withCredentials: true });
        source.addEventListener('open', () => setConnected(true));
        source.addEventListener('message', (event) => {
          try {
            setLastEvent(JSON.parse(event.data) as CatalogRunEvent);
          } catch {
            // Malformed payload — Mercure is enrichment, not source of truth.
          }
        });
        source.addEventListener('error', () => setConnected(false));
      })
      .catch(() => {
        if (!cancelled) {
          setConnected(false);
        }
      });

    return () => {
      cancelled = true;
      source?.close();
      setConnected(false);
    };
  }, [topic]);

  return { connected, lastEvent, topic };
}
