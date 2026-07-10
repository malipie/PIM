import { useQuery } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import {
  type AgentPlanRow,
  type AgentRunStatus,
  approveAgentRun,
  getAgentPlan,
  getAgentRun,
  isRunBusy,
  rejectAgentRun,
  startAgentRun,
} from '@/features/agent/api';

/**
 * AICG-P5-01/02 (#2339/#2340) — the "Ask AI" lifecycle of one product
 * field: start a content run with the field's context, poll it while
 * the loop works, surface the materialized proposal as an inline diff,
 * and resolve it through the SAME approval API the inbox uses.
 *
 * One active ask per page (mirrors the backend's one-active-run rule);
 * starting on another field replaces the tracked run, the previous one
 * stays reachable from the inbox.
 */
export interface AskAiState {
  attributeCode: string;
  runId: string;
  phase: 'running' | 'awaiting_approval' | 'awaiting_input' | 'deciding' | 'error';
  errorDetail: string | null;
  /** The plan row targeting the asked attribute (when awaiting approval). */
  proposal: AgentPlanRow | null;
}

interface UseAskAiArgs {
  productId: string | undefined;
  locale: string | null;
  channel: string | null;
  onAccepted: () => Promise<unknown>;
}

export function useAskAi({ productId, locale, channel, onAccepted }: UseAskAiArgs) {
  const [active, setActive] = useState<{ attributeCode: string; runId: string } | null>(null);
  const [startError, setStartError] = useState<string | null>(null);
  const [deciding, setDeciding] = useState(false);

  const runQuery = useQuery({
    queryKey: ['aicg-ask-run', active?.runId],
    queryFn: () => getAgentRun(active?.runId ?? ''),
    enabled: active !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === undefined || isRunBusy(status) ? 2500 : false;
    },
    retry: 1,
  });
  const status: AgentRunStatus | undefined = runQuery.data?.status;

  const planQuery = useQuery({
    queryKey: ['aicg-ask-plan', active?.runId],
    queryFn: () => getAgentPlan(active?.runId ?? ''),
    enabled: active !== null && status === 'awaiting_approval',
    retry: 1,
  });

  const start = useCallback(
    async (attributeCode: string) => {
      if (productId === undefined) {
        return;
      }
      setStartError(null);
      try {
        const run = await startAgentRun(
          `Wygeneruj treść pola "${attributeCode}" tego produktu na podstawie jego atrybutów (użyj narzędzia treści z domyślnym przepisem).`,
          'chat',
          {
            product_id: productId,
            target_attribute: attributeCode,
            ...(locale !== null ? { locale } : {}),
            ...(channel !== null ? { channel } : {}),
          },
        );
        setActive({ attributeCode, runId: run.id });
      } catch (error) {
        setStartError(error instanceof Error ? error.message : String(error));
        setActive(null);
      }
    },
    [productId, locale, channel],
  );

  const accept = useCallback(async () => {
    if (active === null) {
      return;
    }
    setDeciding(true);
    try {
      await approveAgentRun(active.runId);
      await onAccepted();
      setActive(null);
    } finally {
      setDeciding(false);
    }
  }, [active, onAccepted]);

  const reject = useCallback(async () => {
    if (active === null) {
      return;
    }
    setDeciding(true);
    try {
      await rejectAgentRun(active.runId);
      setActive(null);
    } finally {
      setDeciding(false);
    }
  }, [active]);

  const dismiss = useCallback(() => {
    setActive(null);
    setStartError(null);
  }, []);

  let state: AskAiState | null = null;
  if (active !== null) {
    const proposal =
      planQuery.data?.items.find(
        (row) => row.change_type === 'value' && row.attribute_code === active.attributeCode,
      ) ??
      planQuery.data?.items.find((row) => row.change_type === 'value') ??
      null;
    let phase: AskAiState['phase'] = 'running';
    if (deciding) {
      phase = 'deciding';
    } else if (status === 'awaiting_approval') {
      phase = 'awaiting_approval';
    } else if (status === 'awaiting_input') {
      phase = 'awaiting_input';
    } else if (status === 'error' || runQuery.isError) {
      phase = 'error';
    }
    state = {
      attributeCode: active.attributeCode,
      runId: active.runId,
      phase,
      errorDetail: runQuery.data?.error_message ?? null,
      proposal,
    };
  }

  return { state, startError, start, accept, reject, dismiss };
}
