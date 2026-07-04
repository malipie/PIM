import { ChevronRight, History, Inbox } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

/**
 * Right-column shortcuts on Settings → AI into the agent inbox and run
 * history. Those routes (`/agent/inbox`, `/agent/history`) exist but
 * have no sidebar entry, so this is the primary way a user reaches
 * them — rendered as cards next to the BYOK key panel.
 */
function ShortcutCard({
  to,
  icon,
  title,
  description,
  testid,
}: {
  to: string;
  icon: React.ReactNode;
  title: string;
  description: string;
  testid: string;
}) {
  return (
    <Link
      to={to}
      data-testid={testid}
      className="group flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition-colors hover:border-purple-300 hover:bg-purple-50/40"
    >
      <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg bg-purple-100 text-purple-600">
        {icon}
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1 text-sm font-semibold text-zinc-900">
          {title}
          <ChevronRight
            className="size-4 text-zinc-400 transition-transform group-hover:translate-x-0.5"
            aria-hidden
          />
        </span>
        <span className="mt-0.5 block text-xs text-zinc-500">{description}</span>
      </span>
    </Link>
  );
}

export function AgentShortcuts() {
  const { t } = useTranslation();

  return (
    <aside className="mt-6 space-y-3 lg:mt-0" aria-label="Skróty agenta">
      <ShortcutCard
        to="/agent/inbox"
        testid="agent-shortcut-inbox"
        icon={<Inbox className="size-4" aria-hidden />}
        title={t('agent.settings.shortcut_inbox_title', {
          defaultValue: 'Skrzynka akceptacji',
        })}
        description={t('agent.settings.shortcut_inbox_desc', {
          defaultValue: 'Zatwierdź lub odrzuć propozycje agenta (diff before → after).',
        })}
      />
      <ShortcutCard
        to="/agent/history"
        testid="agent-shortcut-history"
        icon={<History className="size-4" aria-hidden />}
        title={t('agent.settings.shortcut_history_title', {
          defaultValue: 'Historia runów',
        })}
        description={t('agent.settings.shortcut_history_desc', {
          defaultValue: 'Przejrzyj wykonane operacje i cofnij je („Cofnij tę operację").',
        })}
      />
    </aside>
  );
}
