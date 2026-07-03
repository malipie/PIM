import { ArrowRight, ChevronRight, Sparkles } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AGENT_ACCEPTED_THIS_WEEK } from '../mocks';

const QUICK_ACTIONS = [
  { key: 'add_attribute', defaultValue: 'Dodaj atrybut' },
  { key: 'seo_descriptions', defaultValue: 'Generuj opisy SEO' },
  { key: 'bulk_categories', defaultValue: 'Bulk update kategorii' },
  { key: 'translations', defaultValue: 'Tłumaczenia PL→DE' },
  { key: 'feed_export', defaultValue: 'Eksport feed XML' },
] as const;

/**
 * VIEW-13 (#2143) — dark agent command hero. Static mock: the prompt field
 * accepts focus/typing but nothing is submitted, and the quick-action chips
 * are visual no-ops until the agent layer ships (epic 0.7, Faza 2).
 */
export function AgentCommandHero() {
  const { t } = useTranslation();
  const promptId = useId();
  const [prompt, setPrompt] = useState(() =>
    t('dashboard.agent.prompt_example', {
      defaultValue: 'stwórz feed XML dla Google Shopping z kategorii Pneumatyka',
    }),
  );

  return (
    <section
      aria-label={t('dashboard.agent.label', { defaultValue: 'Agent · Claude Sonnet 4.5' })}
      className="rounded-3xl bg-gradient-to-br from-[#0d1626] to-[#16233f] p-6 soft-shadow-lg sm:p-7"
    >
      <div className="flex items-center gap-4">
        <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-white/10">
          <Sparkles className="size-5 text-white" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-medium uppercase tracking-[0.18em] text-white/60">
            {t('dashboard.agent.label', { defaultValue: 'Agent · Claude Sonnet 4.5' })}
          </p>
          <div className="mt-1 flex items-center gap-1.5">
            <ChevronRight className="size-5 shrink-0 text-white/60" aria-hidden />
            <input
              id={promptId}
              type="text"
              value={prompt}
              onChange={(event) => setPrompt(event.target.value)}
              aria-label={t('dashboard.agent.prompt_aria', {
                defaultValue: 'Polecenie dla agenta',
              })}
              autoComplete="off"
              spellCheck={false}
              className="w-full min-w-0 truncate border-0 bg-transparent p-0 text-[18px] font-medium text-white/95 outline-none placeholder:text-white/40 focus:ring-0 sm:text-[21px]"
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
          {/* Visual no-op — submitting a prompt needs the agent layer (Faza 2). */}
          <button
            type="button"
            className="inline-flex items-center gap-2 rounded-2xl bg-white px-5 py-3 text-[14px] font-semibold text-ink transition-colors hover:bg-white/90"
          >
            {t('dashboard.agent.ask', { defaultValue: 'Zapytaj agenta' })}
            <ArrowRight className="size-4" aria-hidden />
          </button>
        </div>
      </div>
      <div className="mt-6 flex flex-wrap items-center gap-2">
        {QUICK_ACTIONS.map(({ key, defaultValue }) => (
          // Visual no-ops until the corresponding agent quick actions exist.
          <button
            key={key}
            type="button"
            className="rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-[12.5px] font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white"
          >
            {t(`dashboard.agent.actions.${key}`, { defaultValue })}
          </button>
        ))}
        <span className="ml-auto text-[12.5px] text-white/60">
          {t('dashboard.agent.accepted_this_week', {
            defaultValue: '{{count}} zaakceptowanych zmian w tym tygodniu',
            count: AGENT_ACCEPTED_THIS_WEEK,
          })}
        </span>
      </div>
    </section>
  );
}
