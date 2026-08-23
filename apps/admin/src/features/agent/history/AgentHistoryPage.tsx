import {
  ArrowRight,
  ChevronDown,
  ChevronRight,
  Loader2,
  MessageSquare,
  Undo2,
  X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ProvenanceBadge } from '@/components/provenance-badge';
import { Button } from '@/components/ui/button';
import {
  type AgentRunDetail,
  type AgentRunStatus,
  type AgentRunSummary,
  cancelAgentRun,
  getAgentRun,
  isRunTerminal,
  listAgentRuns,
  rollbackAgentRun,
} from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

const STATUS_TONES: Record<AgentRunStatus, string> = {
  planning: 'bg-blue-100 text-blue-900',
  awaiting_input: 'bg-amber-100 text-amber-900',
  awaiting_approval: 'bg-purple-100 text-purple-900',
  committing: 'bg-blue-100 text-blue-900',
  done: 'bg-emerald-100 text-emerald-900',
  rejected: 'bg-zinc-200 text-zinc-700',
  cancelled: 'bg-zinc-200 text-zinc-700',
  error: 'bg-red-100 text-red-900',
  rolled_back: 'bg-zinc-200 text-zinc-700',
};

/**
 * AGENT-P6-04 (#1977) — the visible safety net (PRD §8.3/§5.4): the
 * caller's run history (RBAC-scoped by the API) with status, scope,
 * cost and model; expanding a run shows its tool calls (the technical
 * step-by-step; accountability lives in the DH Auditor); "Cofnij tę
 * operację" rolls back a done run through the undo-log. A blocked
 * schema rollback (P5-04 dataless-only boundary) surfaces the 409
 * reason instead of pretending.
 */
export function AgentHistoryPage() {
  const { t } = useTranslation();
  const [runs, setRuns] = useState<AgentRunSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<string | null>(null);
  const [detail, setDetail] = useState<AgentRunDetail | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [rollbackError, setRollbackError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    try {
      const response = await listAgentRuns(1, 50);
      setRuns(response.items);
    } catch (error) {
      setRollbackError(httpErrorDetail(error) ?? String(error));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void reload();
  }, [reload]);

  const toggle = async (run: AgentRunSummary) => {
    if (expanded === run.id) {
      setExpanded(null);
      setDetail(null);
      return;
    }
    setExpanded(run.id);
    setDetail(null);
    try {
      setDetail(await getAgentRun(run.id));
    } catch (error) {
      setRollbackError(httpErrorDetail(error) ?? String(error));
    }
  };

  const rollback = async (run: AgentRunSummary) => {
    setBusyId(run.id);
    setRollbackError(null);
    try {
      await rollbackAgentRun(run.id);
      await reload();
    } catch (error) {
      // P5-04 boundary: a schema-op with data refuses with reasons.
      setRollbackError(httpErrorDetail(error) ?? String(error));
    } finally {
      setBusyId(null);
    }
  };

  // A non-terminal run (awaiting_input left unanswered, a stuck plan)
  // blocks starting a new one ("1 active run per user"). Cancelling it
  // here is the escape hatch so the user isn't wedged.
  const cancel = async (run: AgentRunSummary) => {
    setBusyId(run.id);
    setRollbackError(null);
    try {
      await cancelAgentRun(run.id);
      await reload();
    } catch (error) {
      setRollbackError(httpErrorDetail(error) ?? String(error));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-4" data-testid="agent-history">
      <div className="flex items-end justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">
            {t('agent.history.title', { defaultValue: 'Historia runów agenta' })}
          </h1>
          <p className="text-sm text-zinc-500">
            {t('agent.history.subtitle', {
              defaultValue:
                'Twoje runy: status, zakres, koszt i „Cofnij tę operację” dla zakończonych.',
            })}
          </p>
        </div>
        <Link to="/agent/inbox" className="text-sm underline">
          {t('agent.history.go_inbox', { defaultValue: 'Skrzynka akceptacji' })}
        </Link>
      </div>

      {rollbackError !== null && (
        <p
          className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
          data-testid="agent-history-error"
        >
          {rollbackError}
        </p>
      )}

      {loading ? (
        <p className="flex items-center gap-2 text-sm text-zinc-500" role="status">
          <Loader2 className="size-4 animate-spin" aria-hidden />
          {t('agent.history.loading', { defaultValue: 'Wczytywanie…' })}
        </p>
      ) : runs.length === 0 ? (
        <p className="rounded-xl border border-dashed border-zinc-200 p-6 text-sm text-zinc-500">
          {t('agent.history.empty', {
            defaultValue: 'Jeszcze żadnych runów — otwórz czat agenta albo Cmd+K.',
          })}
        </p>
      ) : (
        <ul className="space-y-2">
          {runs.map((run) => (
            <li
              key={run.id}
              className="rounded-xl border border-zinc-200 bg-white"
              data-testid="agent-history-item"
            >
              <div className="flex flex-wrap items-center gap-2 p-3">
                <button
                  type="button"
                  onClick={() => void toggle(run)}
                  aria-label={t('agent.history.expand', { defaultValue: 'Szczegóły runu' })}
                  className="grid h-7 w-7 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
                >
                  {expanded === run.id ? (
                    <ChevronDown className="size-4" aria-hidden />
                  ) : (
                    <ChevronRight className="size-4" aria-hidden />
                  )}
                </button>
                <span
                  className={cn(
                    'rounded-full px-2 py-0.5 text-[11px] font-medium',
                    STATUS_TONES[run.status],
                  )}
                  data-testid="agent-history-status"
                >
                  {t(`agent.status.${run.status}`, { defaultValue: run.status })}
                </span>
                <span className="min-w-0 flex-1 truncate text-sm">{run.intent}</span>
                <span className="text-xs tabular-nums text-zinc-500">
                  {run.affected_count !== null
                    ? t('agent.history.scope', {
                        defaultValue: '{{count}} zmian · ',
                        count: run.affected_count,
                      })
                    : ''}
                  {'$'}
                  {run.cost_usd}
                  {run.model !== null ? ` · ${run.model}` : ''}
                  {' · '}
                  {new Date(run.started_at).toLocaleString()}
                </span>
                {run.status === 'awaiting_input' && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                      window.dispatchEvent(
                        new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: run.id } }),
                      )
                    }
                    data-testid="agent-history-continue"
                  >
                    <MessageSquare className="mr-1 size-3.5" aria-hidden />
                    {t('agent.history.continue', { defaultValue: 'Kontynuuj w czacie' })}
                  </Button>
                )}
                {run.status === 'awaiting_approval' && (
                  <Button asChild variant="outline" size="sm">
                    <Link
                      to={`/agent/inbox?run=${encodeURIComponent(run.id)}${run.pending_change_batch_id !== null ? `&batch=${encodeURIComponent(run.pending_change_batch_id)}` : ''}`}
                      data-testid="agent-history-proposal-link"
                    >
                      {t('agent.history.view_proposal', { defaultValue: 'Zobacz propozycję' })}
                      <ArrowRight className="ml-1 size-3.5" aria-hidden />
                    </Link>
                  </Button>
                )}
                {!isRunTerminal(run.status) && (
                  <Button
                    variant="ghost"
                    size="sm"
                    disabled={busyId === run.id}
                    onClick={() => void cancel(run)}
                    data-testid="agent-history-cancel"
                  >
                    <X className="mr-1 size-3.5" aria-hidden />
                    {t('agent.history.cancel', { defaultValue: 'Anuluj run' })}
                  </Button>
                )}
                {run.status === 'done' && (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={busyId === run.id}
                    onClick={() => void rollback(run)}
                    data-testid="agent-history-rollback"
                  >
                    <Undo2 className="mr-1 size-3.5" aria-hidden />
                    {t('agent.history.rollback', { defaultValue: 'Cofnij tę operację' })}
                  </Button>
                )}
              </div>
              {expanded === run.id && (
                <div
                  className="border-t border-zinc-100 p-3 text-sm"
                  data-testid="agent-history-detail"
                >
                  {detail === null ? (
                    <p className="flex items-center gap-2 text-zinc-500" role="status">
                      <Loader2 className="size-3 animate-spin" aria-hidden />
                      {t('agent.history.loading', { defaultValue: 'Wczytywanie…' })}
                    </p>
                  ) : (
                    <div className="space-y-2">
                      <div className="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                        <ProvenanceBadge provenance="agent" />
                        <span>
                          {detail.tokens_input + detail.tokens_output}{' '}
                          {t('agent.history.tokens', { defaultValue: 'tokenów' })}
                        </span>
                        {detail.bulk_operation_id !== null && (
                          <span>
                            {t('agent.history.operation', { defaultValue: 'operacja' })}:{' '}
                            <code className="font-mono">{detail.bulk_operation_id}</code>
                          </span>
                        )}
                        <span>
                          {t('agent.history.audit_hint', {
                            defaultValue: 'Pełna odpowiedzialność: Ustawienia → Audyt (agent_run)',
                          })}
                        </span>
                      </div>
                      {detail.messages.length > 0 && (
                        <div className="space-y-1.5" data-testid="agent-history-transcript">
                          {detail.messages.map((message, index) => {
                            const text = message.content
                              .map((block) => ('text' in block ? block.text : ''))
                              .join('')
                              .trim();
                            if (text === '') return null;
                            return (
                              <div
                                // biome-ignore lint/suspicious/noArrayIndexKey: transcript is append-only and static per run
                                key={index}
                                className={cn(
                                  'rounded-lg px-3 py-2 text-[13px]',
                                  message.role === 'user'
                                    ? 'bg-zinc-100 text-zinc-800'
                                    : 'bg-purple-50 text-purple-900',
                                )}
                              >
                                <span className="mb-0.5 block text-[10.5px] font-semibold uppercase tracking-wide opacity-60">
                                  {message.role === 'user'
                                    ? t('agent.history.you', { defaultValue: 'Ty' })
                                    : t('agent.history.agent', { defaultValue: 'Agent' })}
                                </span>
                                <span className="whitespace-pre-wrap">{text}</span>
                              </div>
                            );
                          })}
                        </div>
                      )}
                      {detail.tool_calls.length === 0 ? (
                        <p className="text-zinc-500">
                          {t('agent.history.no_tools', {
                            defaultValue: 'Run bez wywołań narzędzi.',
                          })}
                        </p>
                      ) : (
                        <ul className="space-y-1 font-mono text-[12.5px]">
                          {detail.tool_calls.map((call) => (
                            <li key={call.id} className="rounded bg-zinc-50 px-2 py-1">
                              🔧 {call.tool}
                              {call.duration_ms !== null ? ` · ${call.duration_ms} ms` : ''}
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
