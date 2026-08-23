import { useQueryClient } from '@tanstack/react-query';
import { Loader2, Send, Sparkles, StopCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import {
  type AgentMessageDto,
  type AgentRunDetail,
  type AgentRunStatus,
  cancelAgentRun,
  getAgentRun,
  isRunBusy,
  isRunTerminal,
  sendAgentMessage,
  startAgentRun,
} from '@/features/agent/api';
import { AGENT_PENDING_QUERY_KEY } from '@/features/agent/hooks/useAgentPendingProposals';
import { useAgentRunStream } from '@/features/agent/hooks/useAgentRunStream';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

/** Custom event any surface can dispatch to open the agent chat. */
export const OPEN_AGENT_CHAT_EVENT = 'pim:open-agent-chat';

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

function blockText(message: AgentMessageDto): string {
  return message.content
    .map((block) => {
      if (block.type === 'text' && typeof block.text === 'string') return block.text;
      if (block.type === 'tool_use' && typeof block.name === 'string') return `🔧 ${block.name}`;
      return '';
    })
    .filter(Boolean)
    .join('\n');
}

/**
 * AGENT-P6-01 (#1974) — docked agent chat (Sheet, right side): one of
 * the two equal entry points (next to Cmd+K), same backend loop and
 * approval gate. Renders the persisted transcript with run states;
 * awaiting_input re-enables the composer, awaiting_approval links to
 * the approval inbox. Progress while the loop works server-side is
 * polled (SSE lands in P6-07).
 */
export function AgentChatSheet() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [runId, setRunId] = useState<string | null>(null);
  const [run, setRun] = useState<AgentRunDetail | null>(null);
  const [draft, setDraft] = useState('');
  const [pendingError, setPendingError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [livePhase, setLivePhase] = useState<string | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  // AGENT-P6-07 (#1980) — live phases over SSE; polling stays as the
  // fallback whenever the stream is not connected.
  // SSE drives the live phase label; the status transition itself is
  // guaranteed by polling (see the effect below), so `connected` is not
  // consulted here.
  const { lastEvent } = useAgentRunStream(open ? runId : null);

  const refresh = useCallback(async (id: string) => {
    try {
      const detail = await getAgentRun(id);
      setRun(detail);
      return detail;
    } catch (error) {
      setPendingError(httpErrorDetail(error) ?? String(error));
      return null;
    }
  }, []);

  useEffect(() => {
    // AGENT-P6-02 (#1975) — other surfaces (Cmd+K) start a run and hand
    // the conversation over via the event detail.
    const onOpenEvent = (event: Event) => {
      setOpen(true);
      const adoptedRunId = (event as CustomEvent<{ runId?: string }>).detail?.runId;
      if (typeof adoptedRunId === 'string') {
        setRunId(adoptedRunId);
        void refresh(adoptedRunId);
      }
    };
    window.addEventListener(OPEN_AGENT_CHAT_EVENT, onOpenEvent);
    return () => window.removeEventListener(OPEN_AGENT_CHAT_EVENT, onOpenEvent);
  }, [refresh]);

  // Poll while the loop is busy server-side (planning/committing).
  // Runs REGARDLESS of the SSE stream: with a synchronous loop the run
  // can reach awaiting_input before the stream even connects, so the
  // terminal `status` event is missed and the UI would hang on
  // "Planuję…" forever if polling deferred to SSE. SSE still drives the
  // live phase label; polling guarantees the status transition lands.
  useEffect(() => {
    if (!open || runId === null || run === null || !isRunBusy(run.status)) return;
    const timer = window.setInterval(() => void refresh(runId), 2500);
    return () => window.clearInterval(timer);
  }, [open, runId, run, refresh]);

  // SSE events: a status transition re-reads the run (fresh transcript);
  // a progress tick just updates the live phase label.
  useEffect(() => {
    if (lastEvent === null || runId === null) return;
    if (lastEvent.event === 'status') {
      setLivePhase(null);
      void refresh(runId);
    } else if (typeof lastEvent.phase === 'string') {
      setLivePhase(lastEvent.phase);
    }
  }, [lastEvent, runId, refresh]);

  useEffect(() => {
    if (run?.status === 'awaiting_approval') {
      void queryClient.invalidateQueries({ queryKey: AGENT_PENDING_QUERY_KEY });
    }
  }, [run?.status, queryClient]);

  // biome-ignore lint/correctness/useExhaustiveDependencies: scroll on new messages only
  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [run?.messages.length]);

  const submit = async () => {
    const text = draft.trim();
    if (text === '' || sending) return;
    setSending(true);
    setPendingError(null);
    try {
      if (runId === null || run === null || isRunTerminal(run.status)) {
        const created = await startAgentRun(text, 'chat');
        setRunId(created.id);
        setDraft('');
        await refresh(created.id);
      } else {
        await sendAgentMessage(runId, text);
        setDraft('');
        await refresh(runId);
      }
    } catch (error) {
      setPendingError(httpErrorDetail(error) ?? String(error));
    } finally {
      setSending(false);
    }
  };

  const cancel = async () => {
    if (runId === null) return;
    try {
      await cancelAgentRun(runId);
      await refresh(runId);
    } catch (error) {
      setPendingError(httpErrorDetail(error) ?? String(error));
    }
  };

  const composerEnabled =
    run === null || run.status === 'awaiting_input' || isRunTerminal(run.status);

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label={t('agent.chat.open', { defaultValue: 'Otwórz czat agenta' })}
          data-testid="agent-chat-trigger"
        >
          <Sparkles className="size-4" />
        </Button>
      </SheetTrigger>
      <SheetContent
        side="right"
        className="flex w-full max-w-md flex-col bg-background p-0"
        closeLabel={t('app.close', { defaultValue: 'Zamknij' })}
      >
        <div className="flex items-center gap-2 border-b border-zinc-100 px-4 py-3">
          <Sparkles className="size-4 text-purple-600" aria-hidden />
          <SheetTitle className="text-sm font-semibold">
            {t('agent.chat.title', { defaultValue: 'Agent PIM' })}
          </SheetTitle>
          {run !== null && (
            <span
              className={cn(
                'ml-auto rounded-full px-2 py-0.5 text-[11px] font-medium',
                STATUS_TONES[run.status],
              )}
              data-testid="agent-run-status"
            >
              {t(`agent.status.${run.status}`, { defaultValue: run.status })}
            </span>
          )}
        </div>

        <div
          ref={scrollRef}
          className="flex-1 space-y-3 overflow-auto px-4 py-3"
          data-testid="agent-chat-messages"
        >
          {run === null && (
            <p className="text-sm text-zinc-500">
              {t('agent.chat.empty', {
                defaultValue:
                  'Opisz, co mam zrobić — np. „ustaw cenę 100 wszystkim produktom bez ceny”. Nic nie zapiszę bez Twojej akceptacji.',
              })}
            </p>
          )}
          {run?.messages.map((message) => {
            const text = blockText(message);
            if (text === '') return null;
            return (
              <div
                key={message.id}
                className={cn(
                  'max-w-[85%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm',
                  message.role === 'user'
                    ? 'ml-auto bg-zinc-900 text-white'
                    : 'bg-zinc-100 text-zinc-900',
                  message.role === 'tool' && 'bg-purple-50 text-purple-900',
                )}
              >
                {text}
              </div>
            );
          })}
          {run !== null && isRunBusy(run.status) && (
            <p
              className="flex items-center gap-2 text-sm text-zinc-500"
              role="status"
              data-testid="agent-live-phase"
            >
              <Loader2 className="size-3 animate-spin" aria-hidden />
              {livePhase !== null
                ? t(`agent.phase.${livePhase}`, { defaultValue: livePhase })
                : t(`agent.status.${run.status}`, { defaultValue: run.status })}
            </p>
          )}
          {run?.status === 'awaiting_approval' && (
            <div className="rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-900">
              <p className="font-medium">
                {t('agent.chat.awaiting_approval', {
                  defaultValue: 'Plan gotowy: {{count}} zmian czeka na Twoją akceptację.',
                  count: run.affected_count ?? 0,
                })}
              </p>
              <Link
                to={`/agent/inbox?run=${encodeURIComponent(run.id)}${run.pending_change_batch_id !== null ? `&batch=${encodeURIComponent(run.pending_change_batch_id)}` : ''}`}
                className="mt-1 inline-block underline"
                onClick={() => setOpen(false)}
                data-testid="agent-chat-proposal-link"
              >
                {t('agent.chat.view_proposal', { defaultValue: 'Zobacz propozycję →' })}
              </Link>
            </div>
          )}
          {/* #2605 — awaiting_input with no assistant message is a dead-end for a
              bulk run (it proposed nothing): explain instead of a blank panel
              that reads like a hang. Interactive runs just get the "your turn"
              nudge; the composer below is enabled either way. */}
          {run?.status === 'awaiting_input' &&
            run.messages.filter((message) => message.role !== 'user').length === 0 && (
              <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {run.context.bulk_content ? (
                  <>
                    <p className="font-medium">
                      {t('agent.chat.bulk_no_proposals_title', {
                        defaultValue: 'Agent nie przygotował żadnych propozycji.',
                      })}
                    </p>
                    <p className="mt-1 text-amber-800">
                      {t('agent.chat.bulk_no_proposals_hint', {
                        defaultValue:
                          'Wybrane produkty prawdopodobnie nie mają danych źródłowych (nazwa, opis, marka…) wymaganych przez przepis. Uzupełnij je i spróbuj ponownie.',
                      })}
                    </p>
                  </>
                ) : (
                  <p>
                    {t('agent.chat.awaiting_input_hint', {
                      defaultValue: 'Twoja kolej — napisz poniżej, co agent ma zrobić dalej.',
                    })}
                  </p>
                )}
              </div>
            )}
          {run?.status === 'error' && run.error_message !== null && (
            <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">
              {run.error_message}
            </p>
          )}
          {pendingError !== null && (
            <p
              className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"
              data-testid="agent-chat-error"
            >
              {pendingError}
            </p>
          )}
        </div>

        <form
          className="flex items-end gap-2 border-t border-zinc-100 p-3"
          onSubmit={(event) => {
            event.preventDefault();
            void submit();
          }}
        >
          <textarea
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                void submit();
              }
            }}
            rows={2}
            disabled={!composerEnabled || sending}
            placeholder={
              composerEnabled
                ? t('agent.chat.placeholder', { defaultValue: 'Napisz, co zrobić…' })
                : t('agent.chat.busy_placeholder', { defaultValue: 'Agent pracuje…' })
            }
            aria-label={t('agent.chat.input', { defaultValue: 'Wiadomość do agenta' })}
            className="min-h-[44px] flex-1 resize-none rounded-md border border-zinc-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-zinc-400 disabled:bg-zinc-50"
            data-testid="agent-chat-input"
          />
          {run !== null && !isRunTerminal(run.status) && run.status !== 'awaiting_input' ? (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={() => void cancel()}
              aria-label={t('agent.chat.cancel', { defaultValue: 'Anuluj run' })}
            >
              <StopCircle className="size-4" />
            </Button>
          ) : (
            <Button
              type="submit"
              size="icon"
              disabled={!composerEnabled || sending || draft.trim() === ''}
              aria-label={t('agent.chat.send', { defaultValue: 'Wyślij' })}
              data-testid="agent-chat-send"
            >
              {sending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
            </Button>
          )}
        </form>
      </SheetContent>
    </Sheet>
  );
}
