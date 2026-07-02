import * as React from 'react';

import { useIdentity } from '@/lib/identity';
import { ensureMercureAuthorization, mercureSubscribeUrl, mercureTenantTopic } from '@/lib/mercure';

/**
 * XMLF-P5-05 — SSE subscriber for one feed's run stream (P4-02 publisher).
 * Split from api/runs.ts so the ADR-0021 transport-in-effect guard stays clean: this file owns the EventSource
 * effect and carries no HTTP transport at all.
 */

export interface FeedRunEvent {
  event: 'progress' | 'status';
  run_id: string;
  feed_id: string;
  items_done?: number;
  items_total?: number | null;
  progress_pct?: number | null;
  estimated_seconds_remaining?: number | null;
  status?: string;
  item_count?: number;
  skipped_count?: number;
  warning_count?: number;
  error_message?: string | null;
}

export interface FeedRunsStream {
  connected: boolean;
  lastEvent: FeedRunEvent | null;
  topic: string | null;
}

/**
 * SSE subscriber for one feed's run stream (P4-02 publisher). Mirrors the
 * useExportSessionsStream pattern: mint the mercureAuthorization cookie,
 * open the EventSource, surface the latest event; no-ops without
 * EventSource and on hub errors (REST polling stays the fallback).
 */
export function useFeedRunsStream(feedId: string | null): FeedRunsStream {
  const [connected, setConnected] = React.useState(false);
  const [lastEvent, setLastEvent] = React.useState<FeedRunEvent | null>(null);
  const { identity } = useIdentity();
  const tenantId = identity?.tenant?.id ?? null;
  const topic =
    feedId !== null && tenantId !== null
      ? mercureTenantTopic(tenantId, 'feeds', feedId, 'runs')
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
            setLastEvent(JSON.parse(event.data) as FeedRunEvent);
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
