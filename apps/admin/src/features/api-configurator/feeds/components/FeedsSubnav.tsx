import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { cn } from '@/lib/utils';

const BASE = '/integrations/api-configurator/feeds';

/**
 * XMLF-P5-07 — the two-tab sub-navigation of the Feeds area (design
 * feed-app.jsx): the hub list and the global cross-feed monitor.
 */
export function FeedsSubnav({ active }: { active: 'hub' | 'monitor' }) {
  const { t } = useTranslation();
  return (
    <div
      role="tablist"
      aria-label={t('api_configurator.feeds.subnav.aria')}
      className="flex flex-wrap gap-1 border-b border-zinc-200"
    >
      {(
        [
          ['hub', BASE],
          ['monitor', `${BASE}/monitor`],
        ] as const
      ).map(([id, to]) => (
        <Link
          key={id}
          to={to}
          role="tab"
          aria-selected={active === id}
          className={cn(
            '-mb-px border-b-2 px-3 py-2 text-[13px] font-medium transition',
            active === id
              ? 'border-zinc-900 text-zinc-900'
              : 'border-transparent text-zinc-500 hover:text-zinc-800',
          )}
        >
          {t(`api_configurator.feeds.subnav.${id}`)}
        </Link>
      ))}
    </div>
  );
}
