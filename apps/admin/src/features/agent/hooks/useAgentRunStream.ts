import * as React from 'react';

import { useIdentity } from '@/lib/identity';
import { ensureMercureAuthorization, mercureSubscribeUrl, mercureTenantTopic } from '@/lib/mercure';

export interface AgentRunEvent {
  event: 'progress' | 'status' | 'delta';
  run_id: string;
  phase?: string;
  status?: string;
  delta?: string;
  sequence?: number;
  affected_count?: number | null;
  error_message?: string | null;
}

interface UseAgentRunStreamState {
  connected: boolean;
  lastEvent: AgentRunEvent | null;
}

/**
 * AGENT-P6-07 (#1980) — live phases of one agent run over Mercure SSE
 * (topic `tenant/{tid}/agent-runs/{runId}`, private + tenant-scoped
 * cookie, AUD-001 pattern). Mirrors MercureSubscribeTopics::agentRun().
 * Graceful degradation: any auth/stream failure leaves connected=false
 * and the caller keeps its polling fallback - Mercure down must never
 * crash the panel.
 */
export function useAgentRunStream(runId: string | null): UseAgentRunStreamState {
  const [connected, setConnected] = React.useState(false);
  const [lastEvent, setLastEvent] = React.useState<AgentRunEvent | null>(null);
  const { identity } = useIdentity();
  const tenantId = identity?.tenant?.id ?? null;

  React.useEffect(() => {
    if (runId === null || tenantId === null) {
      setConnected(false);
      setLastEvent(null);
      return;
    }
    if (typeof window === 'undefined' || typeof EventSource === 'undefined') {
      return;
    }

    const topic = mercureTenantTopic(tenantId, 'agent-runs', runId);
    const url = mercureSubscribeUrl(topic);

    let source: EventSource | null = null;
    let cancelled = false;

    ensureMercureAuthorization()
      .then(() => {
        if (cancelled) {
          return;
        }
        source = new EventSource(url, { withCredentials: true });
        source.onopen = () => setConnected(true);
        source.onerror = () => setConnected(false);
        source.onmessage = (message: MessageEvent<string>) => {
          try {
            const parsed = JSON.parse(message.data) as AgentRunEvent;
            if (parsed.run_id === runId) {
              setLastEvent(parsed);
            }
          } catch {
            // Malformed frame: ignore, polling stays the source of truth.
          }
        };
      })
      .catch(() => {
        // Authorization failed (Mercure down / cookie refused): stay on
        // the polling fallback.
        setConnected(false);
      });

    return () => {
      cancelled = true;
      source?.close();
      setConnected(false);
    };
  }, [runId, tenantId]);

  return { connected, lastEvent };
}
