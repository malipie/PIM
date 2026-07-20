import { useList } from '@refinedev/core';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router';

import { PillTabs } from '@/components/ui-v2/pill-tabs';

/**
 * UI-08.9 (#264) — Modeling layout shell. UI-03b polish (#365) added KPI
 * counters next to each tab so operators see the catalogue size at a glance.
 * DP-03 (#2033) moved Channels and Locales in from Settings — they scope
 * attribute values (scopable/localizable), so they belong to the data model.
 * #2669 — the shell header was dropped (the topbar breadcrumb already names
 * the area) and the underline tablist was replaced with the shared v2
 * PillTabs, matching the Exports/Imports hubs.
 *
 * Renders a 6-tab pill bar (Object Types / Attributes / Attribute Groups /
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

/**
 * KPI counter shown inside a tab pill. Uses Refine's useList with
 * `pagination.pageSize: 1` so the network call is small but still hits the
 * server-side `total` accumulator. Undefined while loading — PillTabs hides
 * the badge until the count resolves.
 */
function useResourceCount(resource: NonNullable<TabDef['resource']>): number | undefined {
  const { result, query } = useList({
    resource,
    pagination: { currentPage: 1, pageSize: 1 },
    queryOptions: { staleTime: 30_000 },
  });

  if (query.isLoading) return undefined;
  return result.total ?? result.data.length;
}

export function ModelingLayout() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const navigate = useNavigate();

  const objectTypesCount = useResourceCount('object_types');
  const attributesCount = useResourceCount('attributes');
  const attributeGroupsCount = useResourceCount('attribute_groups');
  const categoriesCount = useResourceCount('categories');
  const channelsCount = useResourceCount('channels');
  const counts: Record<NonNullable<TabDef['resource']>, number | undefined> = {
    object_types: objectTypesCount,
    attributes: attributesCount,
    attribute_groups: attributeGroupsCount,
    categories: categoriesCount,
    channels: channelsCount,
  };

  const activeTab = TABS.find((tab) => pathname.startsWith(tab.to))?.value ?? TABS[0]?.value ?? '';

  return (
    <div className="space-y-6">
      <PillTabs
        ariaLabel={t('modeling.tabs.aria_label')}
        activeId={activeTab}
        onChange={(id) => {
          const tab = TABS.find((candidate) => candidate.value === id);
          if (tab) void navigate(tab.to);
        }}
        items={TABS.map((tab) => ({
          id: tab.value,
          label: t(tab.label),
          count: tab.resource ? counts[tab.resource] : undefined,
        }))}
      />
      <Outlet />
    </div>
  );
}
