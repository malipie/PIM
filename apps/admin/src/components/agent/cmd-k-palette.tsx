import { Sparkles, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { toast } from '@/components/ui/toast';
import { startAgentRun } from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { AGENT_ENABLED } from '@/lib/features';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * VIEW-19 (#550) — Cmd+K palette (USP demo gate).
 *
 * Keyboard shortcut `mod+k` opens the palette; the input dispatches a
 * POST `/api/agent/cmd-k` request to the deterministic planner. On a
 * matching intent the planner returns a `{action, payload, summary}`
 * triple, which we use to drive the same bulk endpoints that power the
 * manual wizard (consistency contract per CLAUDE.md §8.5).
 *
 * Anthropic SDK + tool-use + Mercure SSE streaming + BYOK rotation
 * land in VIEW-19.1 (epik 0.7 / Faza 2). The MVP slice keeps the
 * keyboard shortcut + selection context surface + 6 killer intents
 * demo-ready.
 *
 * Suggested phrasings (mockup `list-v2-overlays.jsx` l. 651-735):
 *   • „Ustaw brand na Festo"
 *   • „Pomnóż price przez 1.10"
 *   • „Skopiuj manufacturer do brand"
 *   • „Wyczyść description_en"
 *   • „Dodaj kategorię akcesoria"
 *   • „Publikuj na shopify"
 */

interface BulkActionResult {
  session_id: string;
  action: string;
  target_count: number;
  success_count: number;
  skipped_count: number;
  error_count: number;
  rollback_available_until?: string;
  completed_at?: string;
}

interface CmdKPaletteProps {
  open: boolean;
  onClose: () => void;
  selectedIds: string[];
  totalMatching: number;
  onApplied: (result: BulkActionResult) => void;
  /** AGENT-P6-02 (#1975) — view context carried into agent runs. */
  objectTypeCode?: string;
  filterDsl?: Record<string, unknown> | null;
}

interface CmdKPlan {
  action: string | null;
  payload: Record<string, unknown> | null;
  summary: string | null;
  fallback_hint?: string;
  selection_context: { selected_ids: string[]; total_matching: number };
}

const SUGGESTIONS: string[] = [
  'Ustaw brand na Festo',
  'Pomnóż price przez 1.10',
  'Skopiuj manufacturer do brand',
  'Wyczyść description_en',
  'Dodaj kategorię akcesoria',
  'Publikuj na shopify',
];

export function CmdKPalette({
  open,
  onClose,
  selectedIds,
  totalMatching,
  onApplied,
  objectTypeCode,
  filterDsl,
}: CmdKPaletteProps) {
  const { t } = useTranslation();
  const [command, setCommand] = useState('');
  const [plan, setPlan] = useState<CmdKPlan | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [recent, setRecent] = useState<string[]>([]);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) {
      setCommand('');
      setPlan(null);
    } else {
      window.requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  if (!open) return null;

  const requestPlan = async (text: string): Promise<void> => {
    setIsLoading(true);
    try {
      const response = await jsonFetch<CmdKPlan>('/api/agent/cmd-k', {
        method: 'POST',
        body: {
          command: text,
          selection_context: {
            selected_ids: selectedIds,
            total_matching: totalMatching,
          },
        },
      });
      setPlan(response);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'plan failed');
    } finally {
      setIsLoading(false);
    }
  };

  const applyPlan = async (): Promise<void> => {
    if (!plan?.action || !plan.payload) return;
    if (selectedIds.length === 0) {
      toast.error(
        t('agent.cmd_k.no_selection', {
          defaultValue: 'Zaznacz produkty zanim wywołasz akcję zbiorczą',
        }),
      );
      return;
    }
    setIsLoading(true);
    try {
      const body: Record<string, unknown> = {
        target_ids: selectedIds,
        payload: plan.payload,
      };
      if (plan.action === 'delete') {
        body.confirmation_count = selectedIds.length;
      }
      const result = await jsonFetch<BulkActionResult>(
        `/api/products/bulk-actions/${plan.action}`,
        { method: 'POST', body },
      );
      toast.success(
        t('agent.cmd_k.applied', {
          count: result.success_count,
          defaultValue: `Zastosowano do ${result.success_count} produktów`,
        }),
      );
      setRecent((prev) => [command, ...prev.filter((c) => c !== command)].slice(0, 5));
      onApplied(result);
      onClose();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'apply failed');
    } finally {
      setIsLoading(false);
    }
  };

  const askAgent = async (): Promise<void> => {
    const text = command.trim();
    if (text === '' || isLoading) return;
    setIsLoading(true);
    try {
      // AGENT-P6-02 (#1975) — free-form intents leave the 6 canned bulk
      // intents to the local planner and go to the REAL agent, carrying
      // exactly what the user is looking at (PRD §5.1).
      const run = await startAgentRun(text, 'cmdk', {
        object_type_code: objectTypeCode,
        filter_dsl: filterDsl ?? null,
        selected_ids: selectedIds,
        total_matching: totalMatching,
      });
      window.dispatchEvent(new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: run.id } }));
      onClose();
    } catch (e) {
      toast.error(httpErrorDetail(e) ?? (e instanceof Error ? e.message : 'agent start failed'));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[55] bg-zinc-900/40 backdrop-blur-sm grid place-items-start pt-24">
      <button
        type="button"
        aria-label="Close backdrop"
        onClick={onClose}
        className="absolute inset-0 cursor-default"
      />
      <div
        className="relative bg-white rounded-3xl shadow-2xl w-[640px] max-w-[94vw] overflow-hidden flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cmd-k-title"
      >
        <div className="px-5 h-14 flex items-center gap-3 border-b border-zinc-100 bg-gradient-to-br from-orange-50/80 to-white">
          <span className="h-8 w-8 rounded-xl bg-orange-500 text-white grid place-items-center">
            <Sparkles className="size-4" />
          </span>
          <div className="leading-tight">
            <div id="cmd-k-title" className="text-[14px] font-semibold tracking-tight">
              {t('agent.cmd_k.title', { defaultValue: 'Cmd+K · Asystent' })}
            </div>
            <div className="text-[11.5px] text-zinc-500 tabular-nums">
              {selectedIds.length}{' '}
              {t('agent.cmd_k.selected_label', { defaultValue: 'zaznaczonych' })} · {totalMatching}{' '}
              {t('agent.cmd_k.matching_label', { defaultValue: 'pasujących' })}
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="ml-auto h-8 w-8 grid place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="p-5 space-y-4">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (command.trim() === '') return;
              void requestPlan(command);
            }}
          >
            <input
              ref={inputRef}
              type="text"
              value={command}
              onChange={(e) => setCommand(e.target.value)}
              placeholder={t('agent.cmd_k.placeholder', {
                defaultValue: 'Np. „pomnóż price przez 1.10 dla wszystkich z brand IS Festo"',
              })}
              className="w-full h-12 px-4 rounded-xl border border-zinc-200 text-[14px] focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500"
            />
          </form>

          {plan ? (
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 space-y-3">
              <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500">
                {t('agent.cmd_k.plan_label', { defaultValue: 'Plan' })}
              </div>
              {plan.action ? (
                <>
                  <div className="text-[14px] font-medium text-zinc-900">{plan.summary}</div>
                  <div className="text-[11.5px] font-mono text-zinc-500">
                    {plan.action} · target={selectedIds.length}
                  </div>
                  <div className="flex items-center gap-2 pt-1">
                    <button
                      type="button"
                      onClick={() => void applyPlan()}
                      disabled={isLoading || selectedIds.length === 0}
                      className="h-9 px-4 rounded-lg bg-orange-600 text-white text-[12.5px] font-medium hover:bg-orange-500 disabled:opacity-50"
                    >
                      {t('agent.cmd_k.apply', { defaultValue: 'Zastosuj' })}
                    </button>
                    <button
                      type="button"
                      onClick={() => setPlan(null)}
                      className="h-9 px-4 rounded-lg border border-zinc-200 text-[12.5px] font-medium text-zinc-700 hover:bg-white"
                    >
                      {t('agent.cmd_k.reject', { defaultValue: 'Odrzuć plan' })}
                    </button>
                  </div>
                </>
              ) : (
                <div className="space-y-2">
                  <div className="text-[12.5px] text-zinc-700">
                    {AGENT_ENABLED
                      ? t('agent.cmd_k.fallback_agent', {
                          defaultValue:
                            'Komenda nie pasuje do szybkich akcji — wyślij ją do agenta PIM.',
                        })
                      : (plan.fallback_hint ??
                        t('agent.cmd_k.fallback', {
                          defaultValue: 'Komenda nie pasuje do MVP intentu.',
                        }))}
                  </div>
                  {AGENT_ENABLED && (
                    <button
                      type="button"
                      onClick={() => void askAgent()}
                      disabled={isLoading}
                      data-testid="cmdk-list-ask-agent"
                      className="h-9 px-4 rounded-lg bg-orange-500 text-white text-[12.5px] font-medium hover:bg-orange-600 disabled:opacity-60"
                    >
                      {t('agent.cmd_k.ask_agent', { defaultValue: 'Wyślij do agenta PIM' })}
                    </button>
                  )}
                </div>
              )}
            </div>
          ) : (
            <div className="space-y-2">
              <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500">
                {t('agent.cmd_k.suggestions_label', { defaultValue: 'Spróbuj' })}
              </div>
              <ul className="space-y-1">
                {SUGGESTIONS.map((s) => (
                  <li key={s}>
                    <button
                      type="button"
                      onClick={() => {
                        setCommand(s);
                        void requestPlan(s);
                      }}
                      className={cn(
                        'w-full text-left px-3 py-2 rounded-lg text-[12.5px]',
                        'hover:bg-zinc-50 text-zinc-700',
                      )}
                    >
                      {s}
                    </button>
                  </li>
                ))}
              </ul>
              {recent.length > 0 ? (
                <>
                  <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 pt-2">
                    {t('agent.cmd_k.recent_label', { defaultValue: 'Ostatnio' })}
                  </div>
                  <ul className="space-y-1">
                    {recent.map((r) => (
                      <li key={r}>
                        <button
                          type="button"
                          onClick={() => {
                            setCommand(r);
                            void requestPlan(r);
                          }}
                          className="w-full text-left px-3 py-2 rounded-lg text-[12.5px] text-zinc-600 hover:bg-zinc-50 font-mono"
                        >
                          {r}
                        </button>
                      </li>
                    ))}
                  </ul>
                </>
              ) : null}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
