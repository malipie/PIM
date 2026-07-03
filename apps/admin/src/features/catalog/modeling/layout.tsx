import { useList } from '@refinedev/core';
import { useTranslation } from 'react-i18next';
import { NavLink, Outlet, useLocation } from 'react-router';

/**
 * UI-08.9 (#264) — Modeling layout shell. UI-03b polish (#365) added KPI
 * counters next to each tab so operators see the catalogue size at a glance.
 * DP-03 (#2033) moved Channels and Locales in from Settings — they scope
 * attribute values (scopable/localizable), so they belong to the data model.
 *
 * Renders a 6-tab top bar (Object Types / Attributes / Attribute Groups /
 * Categories / Channels / Locales) that drives the `/modeling/*` route tree.
 * Active tab is derived from the current pathname so a deep-link (e.g.
 * `/modeling/attributes/{id}`) still highlights the parent tab.
 */

interface TabDef {
  value: string;
  to: string;
  label: string;
  /** Locales has no Refine list resource (custom /api/tenant-locales) — no badge. */
  resource?: 'object_types' | 'attributes' | 'attribute_groups' | 'categories' | 'channels';
}

const TABS: readonly TabDef[] = [
  {
    value: 'object-types',
    to: '/modeling/object-types',
    label: 'modeling.tabs.object_types',
    resource: 'object_types',
  },
  {
    value: 'attributes',
    to: '/modeling/attributes',
    label: 'modeling.tabs.attributes',
    resource: 'attributes',
  },
  {
    value: 'attribute-groups',
    to: '/modeling/attribute-groups',
    label: 'modeling.tabs.attribute_groups',
    resource: 'attribute_groups',
  },
  {
    value: 'categories',
    to: '/modeling/categories',
    label: 'modeling.tabs.categories',
    resource: 'categories',
  },
  {
    value: 'channels',
    to: '/modeling/channels',
    label: 'modeling.tabs.channels',
    resource: 'channels',
  },
  {
    value: 'locales',
    to: '/modeling/locales',
    label: 'modeling.tabs.locales',
  },
] as const;

interface TabBadgeProps {
  resource: NonNullable<TabDef['resource']>;
  isActive: boolean;
}

/**
 * KPI counter rendered next to a tab label. Uses Refine's useList with
 * `pagination.pageSize: 1` so the network call is small but still hits the
 * server-side `total` accumulator. Falls back to a discreet dot while loading.
 */
function TabBadge({ resource, isActive }: TabBadgeProps) {
  const { result, query } = useList({
    resource,
    pagination: { currentPage: 1, pageSize: 1 },
    queryOptions: { staleTime: 30_000 },
  });

  if (query.isLoading) {
    return (
      <span className="ml-2 inline-flex size-1.5 animate-pulse rounded-full bg-muted-foreground/40" />
    );
  }

  const total = result.total ?? result.data.length;

  return (
    <span
      className={
        isActive
          ? 'num ml-2 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-ink/10 px-1.5 text-[11px] font-medium text-ink'
          : 'num ml-2 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-muted px-1.5 text-[11px] font-medium text-muted-foreground'
      }
    >
      {total}
    </span>
  );
}

export function ModelingLayout() {
  const { t } = useTranslation();
  const { pathname } = useLocation();

  const activeTab = TABS.find((tab) => pathname.startsWith(tab.to))?.value ?? TABS[0]?.value ?? '';

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="display text-[28px] font-semibold tracking-tight">{t('modeling.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('modeling.description')}</p>
      </header>
      <div
        className="flex gap-1 border-b"
        role="tablist"
        aria-label={t('modeling.tabs.aria_label')}
      >
        {TABS.map((tab) => {
          const isActive = activeTab === tab.value;
          return (
            <NavLink
              key={tab.value}
              to={tab.to}
              role="tab"
              aria-selected={isActive}
              aria-controls={`modeling-panel-${tab.value}`}
              className={
                isActive
                  ? 'border-ink text-foreground -mb-px flex items-center border-b-2 px-4 py-2 text-sm font-medium'
                  : 'text-muted-foreground hover:text-foreground -mb-px flex items-center border-b-2 border-transparent px-4 py-2 text-sm'
              }
            >
              <span>{t(tab.label)}</span>
              {tab.resource ? <TabBadge resource={tab.resource} isActive={isActive} /> : null}
            </NavLink>
          );
        })}
      </div>
      <div role="tabpanel" id={`modeling-panel-${activeTab}`}>
        <Outlet />
      </div>
    </div>
  );
}
