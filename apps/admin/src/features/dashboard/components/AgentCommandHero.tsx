import { ArrowRight, ChevronRight, Loader2, Sparkles } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { isRunTerminal, listAgentRuns, startAgentRun } from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { useAgentQuickActions } from '@/features/agent/hooks/useAgentQuickActions';
import { AGENT_ENABLED } from '@/lib/features';
import { HttpError, httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * VIEW-13 (#2143) shell, LIVE since #2246 — dark agent command hero, the
 * dashboard entry point into the real agent loop: submit starts a run
 * (`surface: 'dashboard'`) and hands the conversation to the chat sheet
 * (planning phases, approval inbox link). Quick-action chips come from
 * GET /api/agent/capabilities — a new tool that implements
 * ProvidesQuickActionInterface appears here with zero wiring.
 */
export function AgentCommandHero() {
  const { t } = useTranslation();
  const promptId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [prompt, setPrompt] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { capabilities, actions } = useAgentQuickActions();

  // Removability build (ADR-0024): no agent BC, no hero.
  if (!AGENT_ENABLED) return null;

  const unavailable = capabilities !== null && !capabilities.enabled;

  const submit = async (): Promise<void> => {
    const text = prompt.trim();
    if (text === '' || busy || unavailable) return;
    setBusy(true);
    setError(null);
    try {
      const run = await startAgentRun(text, 'dashboard', {});
      window.dispatchEvent(new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: run.id } }));
      setPrompt('');
    } catch (err) {
      if (err instanceof HttpError && err.status === 409) {
        // One active run per user — adopt the ongoing conversation
        // instead of erroring (the 409 body carries no run id).
        const active = await listAgentRuns(1, 5)
          .then(({ items }) => items.find((item) => !isRunTerminal(item.status)))
          .catch(() => undefined);
        window.dispatchEvent(
          new CustomEvent(OPEN_AGENT_CHAT_EVENT, {
            detail: active === undefined ? undefined : { runId: active.id },
          }),
        );
      } else {
        setError(httpErrorDetail(err) ?? String(err));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <section
      aria-label={t('dashboard.agent.label', { defaultValue: 'Agent AI' })}
      className={cn(
        'rounded-3xl bg-gradient-to-br from-[#0d1626] to-[#16233f] p-6 soft-shadow-lg sm:p-7',
        unavailable && 'opacity-75',
      )}
    >
      <form
        className="flex items-center gap-4"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-white/10">
          <Sparkles className="size-5 text-white" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-medium uppercase tracking-[0.18em] text-white/60">
            {t('dashboard.agent.label', { defaultValue: 'Agent AI' })}
          </p>
          <div className="mt-1 flex items-center gap-1.5">
            <ChevronRight className="size-5 shrink-0 text-white/60" aria-hidden />
            <input
              ref={inputRef}
              id={promptId}
              type="text"
              value={prompt}
              onChange={(event) => setPrompt(event.target.value)}
              placeholder={t('dashboard.agent.prompt_example', {
                defaultValue: 'stwórz feed XML dla Google Shopping z kategorii Pneumatyka',
              })}
              aria-label={t('dashboard.agent.prompt_aria', {
                defaultValue: 'Polecenie dla agenta',
              })}
              disabled={unavailable}
              autoComplete="off"
              spellCheck={false}
              data-testid="agent-hero-input"
              className="w-full min-w-0 truncate border-0 bg-transparent p-0 text-[18px] font-medium text-white/95 outline-none placeholder:text-white/40 focus:ring-0 disabled:cursor-not-allowed sm:text-[21px]"
            />
          </div>
        </div>
        <div className="hidden shrink-0 items-center gap-4 md:flex">
          <span className="text-[13px] text-white/60">
            {t('dashboard.agent.press', { defaultValue: 'naciśnij' })}{' '}
            <kbd className="rounded-md bg-white/10 px-1.5 py-0.5 font-mono text-[11px] text-white/80">
              ⌘K
            </kbd>
          </span>
          <button
            type="submit"
            disabled={busy || unavailable}
            data-testid="agent-hero-submit"
            className="inline-flex items-center gap-2 rounded-2xl bg-cta px-5 py-3 text-[14px] font-semibold text-cta-foreground transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-70"
          >
            {t('dashboard.agent.ask', { defaultValue: 'Zapytaj agenta' })}
            {busy ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : (
              <ArrowRight className="size-4" aria-hidden />
            )}
          </button>
        </div>
      </form>
      {error !== null && (
        <p className="mt-3 text-[12.5px] text-red-300" data-testid="agent-hero-error">
          {error}
        </p>
      )}
      {unavailable && (
        <p className="mt-3 text-[12.5px] text-white/70" data-testid="agent-hero-disabled">
          {capabilities?.reason === 'missing_byok_key'
            ? t('dashboard.agent.disabled_byok', {
                defaultValue: 'Agent wymaga skonfigurowania klucza API (BYOK).',
              })
            : t('dashboard.agent.disabled', {
                defaultValue: 'Agent jest niedostępny na tym koncie.',
              })}
          {capabilities?.reason === 'missing_byok_key' && (
            <Link
              to="/settings/ai"
              className="ml-2 font-medium text-white/90 underline underline-offset-2 hover:text-white"
            >
              {t('dashboard.agent.configure_ai', { defaultValue: 'Przejdź do Ustawienia → AI' })}
            </Link>
          )}
        </p>
      )}
      {actions.length > 0 && !unavailable && (
        <div className="mt-6 flex flex-wrap items-center gap-2">
          {actions.map((action) => (
            <button
              key={action.id}
              type="button"
              data-testid="agent-hero-chip"
              onClick={() => {
                setPrompt(action.prompt);
                inputRef.current?.focus();
              }}
              className="rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-[12.5px] font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white"
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </section>
  );
}
