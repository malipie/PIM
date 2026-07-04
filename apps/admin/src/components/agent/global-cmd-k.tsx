import { ArrowRight, CornerDownLeft, Search, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router';

import { startAgentRun } from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { useAgentQuickActions } from '@/features/agent/hooks/useAgentQuickActions';
import { SETTINGS_NAV_GROUPS } from '@/layout/settings-nav-data';
import { AGENT_ENABLED } from '@/lib/features';
import { httpErrorDetail } from '@/lib/http';
import { isMenuRefVisible, useIdentity } from '@/lib/identity';
import { useEffectiveMenu } from '@/lib/use-effective-menu';
import { cn } from '@/lib/utils';

/** Custom event the sidebar pill dispatches to open the palette. */
export const OPEN_CMDK_EVENT = 'pim:open-cmdk';

interface NavEntry {
  label: string;
  route: string;
  group: string;
}

/**
 * AGENT-P6-02 (#1975) — start a REAL agent run from the palette and
 * hand the conversation to the chat sheet (same backend as P6-01).
 */
async function askAgent(
  intent: string,
  context: Record<string, unknown>,
): Promise<{ ok: true } | { ok: false; error: string }> {
  try {
    const run = await startAgentRun(intent, 'cmdk', context);
    window.dispatchEvent(new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: run.id } }));
    return { ok: true };
  } catch (error) {
    return { ok: false, error: httpErrorDetail(error) ?? String(error) };
  }
}

/**
 * NUI-03 (#1422) — global ⌘K palette (design `Dashboard.html` CmdK).
 * The navigation section is REAL: static routes + ObjectTypes from the
 * effective menu + settings sub-pages, fuzzy-filtered, keyboard-driven.
 * The agent section is REAL (AGENT-P6-02): the typed query starts an
 * agent run over the P4-01 API carrying the current pathname as view
 * context and hands off to the chat sheet; quick-action suggestions come
 * from GET /api/agent/capabilities (#2246 — same registry-derived source
 * as the dashboard hero) and prefill the input. Behind the AGENT_ENABLED
 * build flag.
 *
 * On the universal list routes (`/products`, `/objects/:slug`) the
 * list-scoped CmdKPalette (VIEW-19, bulk intents) owns the mod+k
 * shortcut — this host stays silent there to avoid a double binding;
 * the sidebar pill still opens the global palette everywhere.
 */
export function GlobalCmdK() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const { data: menu } = useEffectiveMenu();
  const { identity } = useIdentity();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [cursor, setCursor] = useState(0);
  const [agentBusy, setAgentBusy] = useState(false);
  const [agentError, setAgentError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  // Fetch lazily on first open; cached (5 min) across the session.
  const { actions: agentSuggestions } = useAgentQuickActions(open);

  const submitToAgent = async (intent: string): Promise<void> => {
    const text = intent.trim();
    if (text === '' || agentBusy) return;
    setAgentBusy(true);
    setAgentError(null);
    const outcome = await askAgent(text, { pathname });
    setAgentBusy(false);
    if (outcome.ok) {
      setOpen(false);
      setQuery('');
    } else {
      setAgentError(outcome.error);
    }
  };

  const listOwnsShortcut = pathname === '/products' || pathname.startsWith('/objects/');

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        if (listOwnsShortcut) return;
        event.preventDefault();
        setOpen((prev) => !prev);
      }
      if (event.key === 'Escape') setOpen(false);
    };
    const onOpenEvent = () => setOpen(true);
    window.addEventListener('keydown', onKey);
    window.addEventListener(OPEN_CMDK_EVENT, onOpenEvent);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener(OPEN_CMDK_EVENT, onOpenEvent);
    };
  }, [listOwnsShortcut]);

  useEffect(() => {
    if (open) {
      setQuery('');
      setCursor(0);
      window.requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  const navEntries: NavEntry[] = useMemo(() => {
    const groupNav = t('cmdk.group_nav', { defaultValue: 'Nawigacja' });
    const groupSettings = t('cmdk.group_settings', { defaultValue: 'Ustawienia' });
    const entries: NavEntry[] = [
      { label: t('nav.dashboard'), route: '/dashboard', group: groupNav },
      { label: t('nav.modeling'), route: '/modeling', group: groupNav },
      // DP-03 (#2033): Channels + Locales left SETTINGS_NAV_GROUPS for the
      // modeling shell — keep them reachable from the palette.
      { label: t('modeling.tabs.channels'), route: '/modeling/channels', group: groupNav },
      { label: t('modeling.tabs.locales'), route: '/modeling/locales', group: groupNav },
      { label: t('nav.multimedia'), route: '/assets', group: groupNav },
      { label: t('nav.imports'), route: '/integrations/imports/sessions', group: groupNav },
      { label: t('nav.exports'), route: '/integrations/exports/sessions', group: groupNav },
      {
        label: t('nav.api_configurator'),
        route: '/integrations/api-configurator',
        group: groupNav,
      },
    ];
    for (const item of menu?.visible ?? []) {
      if (item.route === null || item.comingSoon) continue;
      if (!isMenuRefVisible(identity, item.ref)) continue;
      if (item.kind !== 'object_type') continue;
      entries.push({
        label: item.label ?? (item.labelKey ? t(item.labelKey) : item.ref),
        route: item.route,
        group: groupNav,
      });
    }
    if (isMenuRefVisible(identity, 'settings')) {
      for (const group of SETTINGS_NAV_GROUPS) {
        for (const item of group.items) {
          entries.push({ label: t(item.labelKey), route: item.to, group: groupSettings });
        }
      }
    }
    return entries;
  }, [menu, identity, t]);

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (needle === '') return navEntries.slice(0, 8);
    return navEntries.filter((entry) => entry.label.toLowerCase().includes(needle)).slice(0, 8);
  }, [navEntries, query]);

  if (!open) return null;

  const go = (route: string): void => {
    setOpen(false);
    void navigate(route);
  };

  return (
    <div className="fixed inset-0 z-[55] grid place-items-start bg-zinc-900/40 pt-24 backdrop-blur-sm">
      <button
        type="button"
        aria-label={t('app.close', { defaultValue: 'Zamknij' })}
        onClick={() => setOpen(false)}
        className="absolute inset-0 cursor-default"
      />
      <div
        className="relative mx-auto flex w-[640px] max-w-[94vw] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-label={t('cmdk.title', { defaultValue: 'Szukaj lub przejdź' })}
      >
        <div className="flex h-14 items-center gap-3 border-b border-zinc-100 px-5">
          <Search className="size-4 shrink-0 text-zinc-500" aria-hidden />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setCursor(0);
            }}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                setCursor((c) => Math.min(c + 1, filtered.length - 1));
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setCursor((c) => Math.max(c - 1, 0));
              } else if (e.key === 'Enter') {
                const target = filtered[cursor];
                if (target) go(target.route);
              }
            }}
            placeholder={t('cmdk.placeholder', {
              defaultValue: 'Przejdź do… (np. Użytkownicy, Modelowanie, Importy)',
            })}
            className="h-full flex-1 text-[14px] outline-none placeholder:text-zinc-400"
          />
          <kbd className="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 font-mono text-[10px] text-zinc-500">
            esc
          </kbd>
        </div>

        <div className="max-h-[420px] space-y-4 overflow-y-auto p-4">
          <div>
            <div className="px-2 pb-1 text-[10.5px] font-semibold uppercase tracking-wider text-zinc-500">
              {t('cmdk.section_nav', { defaultValue: 'Przejdź do' })}
            </div>
            {filtered.length === 0 ? (
              <p className="px-2 py-2 text-[12.5px] text-zinc-500">
                {t('cmdk.no_results', { defaultValue: 'Brak pasujących stron.' })}
              </p>
            ) : (
              <ul>
                {filtered.map((entry, index) => (
                  <li key={entry.route}>
                    <button
                      type="button"
                      onClick={() => go(entry.route)}
                      onMouseEnter={() => setCursor(index)}
                      className={cn(
                        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px]',
                        index === cursor ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-700',
                      )}
                    >
                      <ArrowRight className="size-3.5 shrink-0 text-zinc-500" aria-hidden />
                      <span className="flex-1 truncate">{entry.label}</span>
                      <span className="text-[10.5px] uppercase tracking-wider text-zinc-500">
                        {entry.group}
                      </span>
                      {index === cursor ? (
                        <CornerDownLeft className="size-3.5 text-zinc-500" aria-hidden />
                      ) : null}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {AGENT_ENABLED && (
            <div>
              <div className="flex items-center gap-1.5 px-2 pb-1 text-[10.5px] font-semibold uppercase tracking-wider text-zinc-500">
                <Sparkles className="size-3 text-orange-500" aria-hidden />
                {t('cmdk.section_agent', { defaultValue: 'Agent' })}
              </div>
              {query.trim() !== '' && (
                <button
                  type="button"
                  onClick={() => void submitToAgent(query)}
                  disabled={agentBusy}
                  data-testid="cmdk-ask-agent"
                  className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] text-zinc-900 hover:bg-orange-50"
                >
                  <Sparkles className="size-3.5 shrink-0 text-orange-500" aria-hidden />
                  <span className="flex-1 truncate">
                    {t('cmdk.ask_agent', { defaultValue: 'Zapytaj agenta:' })} „{query.trim()}”
                  </span>
                </button>
              )}
              {agentSuggestions.length > 0 && (
                <ul>
                  {agentSuggestions.map((action) => (
                    <li key={action.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setQuery(action.prompt);
                          inputRef.current?.focus();
                        }}
                        className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] text-zinc-700 hover:bg-zinc-100"
                      >
                        <Sparkles className="size-3.5 shrink-0 text-zinc-400" aria-hidden />
                        <span className="flex-1 truncate">{action.label}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {agentError !== null && (
                <p
                  className="mx-2 mt-1 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-900"
                  data-testid="cmdk-agent-error"
                >
                  {agentError}
                </p>
              )}
            </div>
          )}
        </div>

        <div className="flex items-center gap-3 border-t border-zinc-100 bg-zinc-50/60 px-5 py-2.5 text-[10.5px] text-zinc-500">
          <span>↑↓ {t('cmdk.kbd_navigate', { defaultValue: 'nawiguj' })}</span>
          <span>↵ {t('cmdk.kbd_open', { defaultValue: 'otwórz' })}</span>
          <span>esc {t('cmdk.kbd_close', { defaultValue: 'zamknij' })}</span>
        </div>
      </div>
    </div>
  );
}
