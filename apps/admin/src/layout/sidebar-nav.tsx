import { useGetIdentity } from '@refinedev/core';
import {
  ArrowRight,
  Boxes,
  ChevronDown,
  Cog,
  FileLock2,
  FileText,
  Image,
  LayoutDashboard,
  type LucideIcon,
  Package,
  Plug2,
  Plus,
  Search,
  Settings2,
  Tag,
  Workflow,
  Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { NavLink, useLocation } from 'react-router';

import { MockBadge } from '@/components/ui/mock-badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { isMenuRefVisible, useIdentity } from '@/lib/identity';
import { type EffectiveMenuItem, useEffectiveMenu } from '@/lib/use-effective-menu';
import { cn } from '@/lib/utils';

import { SETTINGS_NAV_GROUPS } from './settings-nav-data';
import { formatNavCount, type NavCountSource, useNavCounts } from './use-nav-counts';
import { UserMenu } from './user-menu';

interface SidebarNavProps {
  onNavigate?: () => void;
}

interface RefineIdentity {
  id: string;
  name: string;
  email: string;
  roles: string[];
  tenant: { id: string; code: string; name: string } | null;
}

/**
 * VIEW-08 (#427) — string→LucideIcon lookup. Backend ships icon names
 * (`SystemMenuItemRegistry` / `ObjectType.icon`). Fallback: `Boxes`.
 */
const ICON_MAP: Record<string, LucideIcon> = {
  Boxes,
  Cog,
  FileText,
  Image,
  LayoutDashboard,
  Package,
  Plug2,
  Settings2,
  Tag,
  Workflow,
  Wrench,
};

const FALLBACK_ITEMS: EffectiveMenuItem[] = [
  {
    id: 'system:dashboard',
    kind: 'system',
    ref: 'dashboard',
    label: null,
    labelKey: 'nav.dashboard',
    icon: 'LayoutDashboard',
    route: '/dashboard',
    comingSoon: false,
    protected: false,
  },
  {
    id: 'system:catalogs_pdf',
    kind: 'system',
    ref: 'catalogs_pdf',
    label: null,
    labelKey: 'nav.catalogsPdf',
    icon: 'FileText',
    route: '/catalogs-pdf',
    comingSoon: false,
    protected: true,
  },
  {
    id: 'system:multimedia',
    kind: 'system',
    ref: 'multimedia',
    label: null,
    labelKey: 'nav.multimedia',
    icon: 'Image',
    route: '/assets',
    comingSoon: false,
    protected: false,
  },
  {
    id: 'system:workflow',
    kind: 'system',
    ref: 'workflow',
    label: null,
    labelKey: 'nav.workflow',
    icon: 'Workflow',
    route: null,
    comingSoon: true,
    protected: false,
  },
  {
    id: 'system:integrations',
    kind: 'system',
    ref: 'integrations',
    label: null,
    labelKey: 'nav.integrations',
    icon: 'Plug2',
    route: '/integrations',
    comingSoon: false,
    protected: false,
  },
  {
    id: 'system:settings',
    kind: 'system',
    ref: 'settings',
    label: null,
    labelKey: 'nav.settings',
    icon: 'Cog',
    route: '/settings',
    comingSoon: false,
    protected: true,
  },
  {
    id: 'system:modeling',
    kind: 'system',
    ref: 'modeling',
    label: null,
    labelKey: 'nav.modeling',
    icon: 'Settings2',
    route: '/modeling',
    comingSoon: false,
    protected: true,
  },
];

/**
 * EXR-03 — second-level menu under "Integracje". Children are static FE
 * structure (routes already exist in App.tsx); counts come from
 * `useNavCounts`. The parent expands/collapses; a deep link onto any
 * child route opens it automatically.
 */
interface IntegrationChild {
  key: string;
  labelKey: string;
  route: string;
  /** Count source key in useNavCounts results (optional). */
  countKey?: string;
  /** NUI-01 — pulsing dot next to the count while the counter is > 0 (live sessions). */
  live?: boolean;
  /** A sibling owns this deeper prefix — do not highlight this item there. */
  excludePrefix?: string;
}

const INTEGRATION_CHILDREN: IntegrationChild[] = [
  {
    key: 'imports',
    labelKey: 'nav.imports',
    route: '/integrations/imports/sessions',
    countKey: 'child:imports',
    live: true,
  },
  { key: 'exports', labelKey: 'nav.exports', route: '/integrations/exports/sessions' },
  {
    key: 'api_configurator',
    labelKey: 'nav.api_configurator',
    route: '/integrations/api-configurator',
    // XMLF-FUP-01 — the feeds area is its own menu entry below; without this
    // exclusion both items would light up on /api-configurator/feeds/*.
    excludePrefix: '/integrations/api-configurator/feeds',
  },
  {
    key: 'xml_configurator',
    labelKey: 'nav.xml_configurator',
    route: '/integrations/api-configurator/feeds',
  },
];

const baseLeafClass =
  'group relative flex w-full items-center gap-3 rounded-xl px-3 py-2 text-[14px] font-medium transition';

const disabledLeafClass = cn(baseLeafClass, 'cursor-not-allowed text-zinc-500');

const leafLinkClass = ({ isActive }: { isActive: boolean }): string =>
  cn(baseLeafClass, isActive ? 'bg-zinc-900 text-white' : 'text-zinc-700 hover:bg-zinc-100');

export function SidebarNav({ onNavigate }: SidebarNavProps) {
  const { t } = useTranslation();
  const { data, isError } = useEffectiveMenu();
  const { identity } = useIdentity();
  const { data: refineIdentity } = useGetIdentity<RefineIdentity>();
  const { pathname } = useLocation();

  const rawItems: EffectiveMenuItem[] = data && !isError ? data.visible : FALLBACK_ITEMS;
  const items: EffectiveMenuItem[] = rawItems.filter((item) =>
    isMenuRefVisible(identity, item.ref),
  );

  const integrationsRouteActive = pathname.startsWith('/integrations');
  const [integrationsOpen, setIntegrationsOpen] = useState(integrationsRouteActive);
  useEffect(() => {
    // Deep link onto /integrations/exports/... opens the parent (EXR-03 AC).
    if (integrationsRouteActive) {
      setIntegrationsOpen(true);
    }
  }, [integrationsRouteActive]);

  const countSources: NavCountSource[] = [
    ...items
      .filter((item) => item.kind === 'object_type' && item.route !== null)
      .map((item) => ({ key: item.id, objectTypeId: item.ref })),
    ...items
      .filter((item) => item.ref === 'multimedia' && item.route !== null)
      .map((item) => ({ key: item.id, system: 'assets' as const })),
    { key: 'child:imports', system: 'imports_active' as const },
  ];
  const counts = useNavCounts(countSources);

  const renderCountBadge = (key: string | undefined, active = false) => {
    const value = key !== undefined ? counts[key] : undefined;
    if (value === undefined) return null;
    return (
      <span
        className={cn(
          'num ml-auto font-mono text-[11px] font-medium',
          active ? 'text-white/70' : 'text-zinc-500',
        )}
      >
        {formatNavCount(value)}
      </span>
    );
  };

  const renderLabel = (item: EffectiveMenuItem): string => {
    if (item.label !== null) return item.label;
    if (item.labelKey) return t(item.labelKey);
    return item.ref;
  };

  const renderIntegrationsParent = (item: EffectiveMenuItem) => {
    const Icon = ICON_MAP[item.icon] ?? Plug2;
    const labelText = renderLabel(item);
    return (
      <div key={item.id}>
        <button
          type="button"
          aria-expanded={integrationsOpen}
          aria-controls="nav-integrations-children"
          onClick={() => setIntegrationsOpen((open) => !open)}
          className={cn(
            baseLeafClass,
            integrationsRouteActive && !integrationsOpen
              ? 'bg-zinc-900 text-white'
              : 'text-zinc-700 hover:bg-zinc-100',
          )}
        >
          <Icon
            className={cn(
              'size-4',
              integrationsRouteActive && !integrationsOpen ? 'text-white/90' : 'text-zinc-500',
            )}
          />
          <span className="flex-1 text-left">{labelText}</span>
          <ChevronDown
            aria-hidden="true"
            className={cn(
              'size-3.5 text-zinc-500 transition-transform',
              integrationsOpen && 'rotate-180',
            )}
          />
        </button>
        {integrationsOpen && (
          <div id="nav-integrations-children" className="mt-0.5 flex flex-col gap-0.5">
            {INTEGRATION_CHILDREN.map((child) => (
              <NavLink
                key={child.key}
                to={child.route}
                onClick={onNavigate}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-xl py-1.5 pr-3 pl-10 text-[13px] font-medium transition',
                    isActive &&
                      (child.excludePrefix === undefined ||
                        !pathname.startsWith(child.excludePrefix))
                      ? 'bg-zinc-100 text-ink'
                      : 'text-zinc-500 hover:bg-zinc-50',
                  )
                }
                end={false}
              >
                <span className="flex-1">{t(child.labelKey)}</span>
                {child.live && child.countKey !== undefined && (counts[child.countKey] ?? 0) > 0 ? (
                  <span
                    className="size-1.5 animate-pulse rounded-full bg-emerald-500"
                    aria-hidden
                    data-testid={`nav-live-dot-${child.key}`}
                  />
                ) : null}
                {renderCountBadge(child.countKey)}
              </NavLink>
            ))}
          </div>
        )}
      </div>
    );
  };

  /**
   * NUI-01 (#1420) — settings sub-navigation lives in the MAIN sidebar as an
   * indented subtree under "Ustawienia" (design `settings/page.jsx`), shown
   * while any /settings/* route is active. Replaces the second sidebar that
   * `SettingsLayout` used to render.
   */
  const renderSettingsParent = (item: EffectiveMenuItem) => {
    const Icon = ICON_MAP[item.icon] ?? Cog;
    const labelText = renderLabel(item);
    const settingsActive = pathname.startsWith('/settings');
    return (
      <div key={item.id}>
        <NavLink
          to={item.route ?? '/settings'}
          onClick={onNavigate}
          className={leafLinkClass}
          end={false}
        >
          {({ isActive }) => (
            <>
              <Icon className={cn('size-4', isActive ? 'text-white/90' : 'text-zinc-500')} />
              <span className="flex-1">{labelText}</span>
            </>
          )}
        </NavLink>
        {settingsActive && (
          <div
            className="my-1 ml-[18px] space-y-2.5 border-l border-zinc-200 pb-1 pl-3"
            data-testid="nav-settings-subtree"
          >
            {SETTINGS_NAV_GROUPS.map((group) => (
              <div key={group.id}>
                <div className="mt-1.5 mb-0.5 px-2 text-[10px] font-medium uppercase tracking-wider text-zinc-500">
                  {t(group.labelKey)}
                </div>
                {group.items.map((sub) => (
                  <NavLink
                    key={sub.to}
                    to={sub.to}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                      cn(
                        'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[12.5px] transition',
                        isActive
                          ? 'bg-zinc-100 font-semibold text-zinc-900'
                          : 'text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900',
                      )
                    }
                    end={false}
                  >
                    {({ isActive }) => (
                      <>
                        <span className="flex-1 truncate">{t(sub.labelKey)}</span>
                        {sub.primary && !isActive ? (
                          <span className="size-1.5 rounded-full bg-zinc-300" aria-hidden />
                        ) : null}
                        {sub.ownerOnly ? (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="text-[9.5px] font-medium text-amber-700">
                                {t('settings.owner_only_badge', { defaultValue: 'owner' })}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent side="right">
                              {t('settings.owner_only_tooltip', {
                                defaultValue: 'Tenant Owner only',
                              })}
                            </TooltipContent>
                          </Tooltip>
                        ) : null}
                      </>
                    )}
                  </NavLink>
                ))}
              </div>
            ))}
            <SettingsAuditCard />
          </div>
        )}
      </div>
    );
  };

  const renderLeaf = (item: EffectiveMenuItem) => {
    const Icon = ICON_MAP[item.icon] ?? Boxes;
    const labelText = renderLabel(item);

    if (item.ref === 'integrations' && item.route !== null && !item.comingSoon) {
      return renderIntegrationsParent(item);
    }

    // NUI-01 (#1420) — custom ObjectTypes render exactly like built-in items
    // (the violet dashed treatment is gone from the design).
    if (item.ref === 'settings' && item.route !== null && !item.comingSoon) {
      return renderSettingsParent(item);
    }

    if (item.comingSoon || item.route === null) {
      return (
        <span key={item.id} className={disabledLeafClass} aria-disabled="true">
          <Icon className="size-4 text-zinc-500" />
          <span className="flex-1">{labelText}</span>
          <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[9.5px] font-semibold uppercase tracking-wider text-zinc-500">
            {t('nav.soon')}
          </span>
        </span>
      );
    }

    return (
      <NavLink
        key={item.id}
        to={item.route}
        onClick={onNavigate}
        className={leafLinkClass}
        end={false}
      >
        {({ isActive }) => (
          <>
            <Icon className={cn('size-4', isActive ? 'text-white/90' : 'text-zinc-500')} />
            <span className="flex-1">{labelText}</span>
            {renderCountBadge(item.id, isActive)}
          </>
        )}
      </NavLink>
    );
  };

  const brandSubtitle =
    refineIdentity?.tenant?.name ?? t('app.brand_subtitle_fallback', { defaultValue: 'Workspace' });

  const agentTooltip = t('topbar.agent_pill_tooltip', {
    defaultValue: 'Nawigacja ⌘K działa; sekcja agenta = MOCK (epik 0.7, Faza 2)',
  });

  return (
    <>
      <div className="flex items-center gap-2.5 px-2 pb-3 pt-1">
        <div
          className="grid h-9 w-9 place-items-center rounded-2xl bg-zinc-900 text-white"
          aria-hidden
        >
          <span className="text-[15px] font-bold">P</span>
        </div>
        <div className="min-w-0 leading-tight">
          <div className="truncate text-[15px] font-semibold tracking-tight text-zinc-900">
            {t('app.title')}
          </div>
          <div className="truncate text-[11px] text-zinc-500">{brandSubtitle}</div>
        </div>
      </div>

      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            onClick={() => window.dispatchEvent(new CustomEvent('pim:open-cmdk'))}
            className="mt-1 flex w-full items-center gap-2 rounded-2xl bg-white px-3 py-2.5 text-left shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)] transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label={t('topbar.search_agent_placeholder', {
              defaultValue: 'Zapytaj agenta lub szukaj...',
            })}
          >
            <Search className="size-4 shrink-0 text-zinc-500" aria-hidden />
            <span className="flex-1 truncate text-[13px] text-zinc-500">
              {t('topbar.search_agent_placeholder', {
                defaultValue: 'Zapytaj agenta lub szukaj...',
              })}
            </span>
            <kbd className="hidden rounded border border-zinc-200 bg-white px-1.5 py-0.5 font-mono text-[10px] font-medium text-zinc-500 sm:inline">
              ⌘K
            </kbd>
            <MockBadge tooltip={agentTooltip} />
          </button>
        </TooltipTrigger>
        <TooltipContent side="right">{agentTooltip}</TooltipContent>
      </Tooltip>

      <nav className="mt-5 flex-1 overflow-y-auto">
        <div className="px-3 pb-1.5 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
          {t('nav.workspace_label', { defaultValue: 'Workspace' })}
        </div>
        <div className="flex flex-col gap-0.5">{items.map(renderLeaf)}</div>

        <NavLink
          to="/modeling/object-types/new"
          onClick={onNavigate}
          className="mt-3 flex w-full items-center gap-3 rounded-xl border border-dashed border-zinc-200 px-3 py-2 text-[13px] text-zinc-500 transition hover:border-orange-300 hover:bg-orange-50/60 hover:text-orange-700"
        >
          <Plus className="size-4 text-zinc-500" aria-hidden />
          <span className="flex-1 text-left">
            {t('nav.add_custom_module', { defaultValue: 'Dodaj własny moduł' })}
          </span>
        </NavLink>
      </nav>

      <div className="mt-3">
        <UserMenu />
      </div>
    </>
  );
}

/**
 * Moved from `SettingsLayout` (NUI-01 #1420) — renders at the bottom of the
 * settings subtree. The audit-log link stays disabled until Phase 7 (#724).
 */
function SettingsAuditCard() {
  const { t } = useTranslation();
  const tooltip = t('settings.audit_card_coming_soon_tooltip', {
    defaultValue: 'Audit log UI lands in Phase 7 (#724).',
  });

  return (
    <div className="mt-2 mr-1 rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
      <div className="mb-1 flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-zinc-500">
        <FileLock2 className="size-3 text-zinc-500" aria-hidden />
        {t('settings.audit_card_title', { defaultValue: 'Audyt zmian' })}
      </div>
      <p className="text-[11px] leading-snug text-zinc-700">
        {t('settings.audit_card_body', {
          defaultValue: 'Każda zmiana w Ustawieniach jest logowana z user_id, IP, old/new value.',
        })}
      </p>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            disabled
            className="mt-1.5 inline-flex cursor-not-allowed items-center gap-1 text-[11px] font-medium text-zinc-500"
          >
            {t('settings.audit_card_link', { defaultValue: 'Zobacz audit log' })}
            <ArrowRight className="size-3" aria-hidden />
          </button>
        </TooltipTrigger>
        <TooltipContent side="right">{tooltip}</TooltipContent>
      </Tooltip>
    </div>
  );
}
