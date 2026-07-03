import { Sparkles, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { toast } from '@/components/ui/toast';
import { startAgentRun } from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { AGENT_ENABLED } from '@/lib/features';
import type { FilterDsl } from '@/lib/filters/filter-dsl';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * VIEW-19 / #2163 — Cmd+K palette.
 *
 * Every command goes to the PIM agent: it starts an agent run (LLM →
 * plan → pending_changes) and hands off to the chat sheet, so nothing is
 * written to the catalog without passing the approval inbox. The old
 * deterministic quick-action path applied bulk changes IMMEDIATELY with
 * no approval — inconsistent with the agent's safety model and surprising
 * (a change landed with no confirmation and no feedback), so it was
 * removed. The single rule now: agent = always approval.
 *
 * Suggested phrasings just pre-fill the input; the user still submits.
 */

interface CmdKPaletteProps {
  open: boolean;
  onClose: () => void;
  selectedIds: string[];
  totalMatching: number;
  /** AGENT-P6-02 (#1975) — view context carried into agent runs. */
  objectTypeCode?: string;
  filterDsl?: FilterDsl | null;
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
  objectTypeCode,
  filterDsl,
}: CmdKPaletteProps) {
  const { t } = useTranslation();
  const [command, setCommand] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) {
      setCommand('');
    } else {
      window.requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  if (!open) return null;

  const askAgent = async (raw: string): Promise<void> => {
    const text = raw.trim();
    if (text === '' || isLoading) return;
    if (!AGENT_ENABLED) {
      toast.error(
        t('agent.cmd_k.disabled', {
          defaultValue: 'Agent PIM jest wyłączony — włącz go w Ustawieniach › AI.',
        }),
      );
      return;
    }
    setIsLoading(true);
    try {
      // #2163 — every command goes to the REAL agent, carrying exactly
      // what the user is looking at (view + selection). The agent
      // materializes changes into the approval inbox; nothing is applied
      // without an explicit accept.
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
              void askAgent(command);
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

          <div className="rounded-2xl border border-orange-100 bg-orange-50/50 px-4 py-2.5 text-[11.5px] text-zinc-600">
            {t('agent.cmd_k.approval_hint', {
              defaultValue:
                'Komenda trafi do agenta PIM — przygotuje zmiany do akceptacji. Nic nie wchodzi bez Twojego „Akceptuj".',
            })}
          </div>

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
                      inputRef.current?.focus();
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
          </div>
        </div>
      </div>
    </div>
  );
}
