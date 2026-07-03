import { History } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type BreadcrumbItem, PageHeader } from '@/components/ui-v2/page-header';

import { BulkSessionsPopover } from './bulk-sessions-popover';
import { LanguageSwitcher } from './language-switcher';
import { NotificationsBell } from './notifications-bell';
import { usePageActionsSlot } from './page-actions-context';

interface RouteCrumb {
  match: RegExp;
  /** i18n keys for segments after "Workspace"; href for all but the last. */
  segments: Array<{ key: string; href?: string }>;
}

/**
 * Route → breadcrumb mapping (EXR-03). Integration sub-pages get the
 * three-segment form (Workspace / Integracje / Eksporty); everything
 * else keeps Workspace / <page>. Order matters — first match wins.
 */
const ROUTE_CRUMBS: RouteCrumb[] = [
  {
    match: /^\/integrations\/exports/,
    segments: [{ key: 'nav.integrations', href: '/integrations' }, { key: 'nav.exports' }],
  },
  {
    match: /^\/integrations\/imports/,
    segments: [{ key: 'nav.integrations', href: '/integrations' }, { key: 'nav.imports' }],
  },
  {
    match: /^\/integrations\/api-configurator/,
    segments: [{ key: 'nav.integrations', href: '/integrations' }, { key: 'nav.api_configurator' }],
  },
  { match: /^\/integrations/, segments: [{ key: 'nav.integrations' }] },
  { match: /^\/dashboard/, segments: [{ key: 'nav.dashboard' }] },
  { match: /^\/products/, segments: [{ key: 'nav.products' }] },
  { match: /^\/modeling/, segments: [{ key: 'nav.modeling' }] },
  { match: /^\/assets/, segments: [{ key: 'nav.multimedia' }] },
  { match: /^\/catalogs-pdf/, segments: [{ key: 'nav.catalogsPdf' }] },
  { match: /^\/settings/, segments: [{ key: 'nav.settings' }] },
];

/**
 * Global topbar v2 (EXR-03): ui-v2 PageHeader breadcrumb + per-page
 * action slot (PageActionsContext) + fixed actions (language switcher,
 * disabled history icon, notifications). The audit-log status pill was
 * removed in VIEW-13 (#2143) per operator decision.
 */
export function TopbarV2() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const pageActions = usePageActionsSlot();

  const crumb = ROUTE_CRUMBS.find(({ match }) => match.test(pathname));
  const items: BreadcrumbItem[] = [
    { label: t('topbar.workspace', { defaultValue: 'Workspace' }), href: '/dashboard' },
    ...(crumb?.segments.map((segment) => ({ label: t(segment.key), href: segment.href })) ?? []),
  ];

  return (
    <PageHeader
      className="h-14 px-3 md:px-6"
      items={items}
      actions={
        <>
          {pageActions}
          <LanguageSwitcher />
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                disabled
                aria-label={t('topbar.history', { defaultValue: 'Historia' })}
                className="grid h-9 w-9 cursor-not-allowed place-items-center rounded-xl text-zinc-300"
              >
                <History className="size-4" aria-hidden />
              </button>
            </TooltipTrigger>
            <TooltipContent>{t('ui_v2.soon_tooltip')}</TooltipContent>
          </Tooltip>
          <BulkSessionsPopover />
          <NotificationsBell />
        </>
      }
    />
  );
}
