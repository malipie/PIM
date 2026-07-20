import { useList } from '@refinedev/core';
import { useQueries } from '@tanstack/react-query';
import { ChevronRight, Lock, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { BuiltInLockBadge } from '@/components/modeling/built-in-lock-badge';
import { Card } from '@/components/ui/card';
import { TopbarCta } from '@/components/ui-v2/topbar-cta';
import { resolveLabel } from '@/features/catalog/attributes/list';
import { usePageActions } from '@/layout/page-actions-context';
import { jsonFetch } from '@/lib/http';
import { isLegacyOptionalSystemGroupCode } from '@/lib/legacy-attribute-groups';
import { cn } from '@/lib/utils';

interface AttributeGroupRow {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  description?: Record<string, string> | string | null;
  icon?: string | null;
  color?: string | null;
  systemGroup?: boolean;
  position?: number;
}

interface UsageResp {
  attributeCount: number;
  directlyAttachedTo: {
    objectTypes: { id: string }[];
    categories: { id: string }[];
  };
}

/**
 * VIEW-03b — pixel-perfect rebuild of `AttributeGroupsView`
 * (`groups-categories.jsx:3–80`):
 *
 *   - caption "{N} grup atrybutów" + orange ⭐ FIRST-CLASS ENTITY badge.
 *   - title "Attribute Groups", description Pimcore/Akeneo positioning.
 *   - CTA "+ Nowa grupa" (zinc-900) → /modeling/attribute-groups/new.
 *   - Single Card with sticky search top, 2 sections:
 *     * System — lock badge prefix.
 *     * Business groups.
 *   - Each row: 6-col grid (icon 44 / name+desc 1.6fr / code 1fr / N attr
 *     120 / N typy·N kat. 120 / chevron 28). Hover bg-zinc-50/70. Click →
 *     /modeling/attribute-groups/{id} (router pushes UUID; ticket VIEW-03b
 *     keeps the existing :id route for backward compat with VIEW-03).
 */
export function AttributeGroupsListPage() {
  const { t, i18n } = useTranslation();
  const [search, setSearch] = useState('');

  usePageActions(
    useMemo(
      () => (
        <TopbarCta to="/modeling/attribute-groups/new">
          {t('modeling.attributeGroups.create_action', { defaultValue: 'Nowa grupa' })}
        </TopbarCta>
      ),
      [t],
    ),
  );

  const { result, query } = useList<AttributeGroupRow>({
    resource: 'attribute_groups',
    pagination: { mode: 'off' },
    queryOptions: { refetchOnMount: 'always', refetchOnWindowFocus: true, staleTime: 0 },
  });

  const groups = result.data;
  const isLoading = query.isLoading;
  const usage = useGroupsUsage(groups);

  const filtered = groups.filter((row) => {
    if (search === '') return true;
    const needle = search.toLowerCase();
    if (row.code.toLowerCase().includes(needle)) return true;
    const labelStr = resolveLabel(row.label, i18n.language).toLowerCase();
    return labelStr.includes(needle);
  });

  const sortByPosition = (a: AttributeGroupRow, b: AttributeGroupRow) =>
    (a.position ?? 0) - (b.position ?? 0);
  const systemGroups = filtered.filter((r) => r.systemGroup === true).sort(sortByPosition);
  const businessGroups = filtered.filter((r) => r.systemGroup !== true).sort(sortByPosition);
  // Legacy `audit` (#1074) and `relations` (#1080) are removable even when
  // `is_system_group=true`. Keep the lock affordance on the "System" header
  // only when a truly-locked group is still present, so audit/relations-only
  // databases don't show misleading UX.
  const systemSectionHasLockedGroup = systemGroups.some(
    (row) => !isLegacyOptionalSystemGroupCode(row.code),
  );

  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-[13px] font-medium text-muted-foreground">
              {t('modeling.attributeGroups.list_caption', {
                defaultValue: '{{count}} grup atrybutów',
                count: groups.length,
              })}
            </span>
            <span className="rounded bg-orange-50 px-1.5 py-0.5 text-[10.5px] font-semibold uppercase tracking-wider text-orange-800">
              {t('modeling.attributeGroups.list_first_class_badge', {
                defaultValue: '⭐ first-class entity',
              })}
            </span>
          </div>
          <h1 className="font-display text-[28px] font-semibold tracking-tight">
            {t('modeling.attributeGroups.list_title', { defaultValue: 'Attribute Groups' })}
          </h1>
          <p className="mt-1 max-w-3xl text-[13px] text-muted-foreground">
            {t('modeling.attributeGroups.list_description', {
              defaultValue:
                'Grupa atrybutów jako wymienialna jednostka — przypinasz ją do ObjectType (globalnie) lub Category (z dziedziczeniem). Pimcore nie ma tej abstrakcji, Akeneo traktuje ją tylko jako sortowanie. U nas — własny URL, audit, wersjonowanie.',
            })}
          </p>
        </div>
      </div>

      <Card className="p-2">
        <div className="flex items-center gap-3 border-b border-zinc-100 px-4 py-3">
          <Search className="size-4 text-muted-foreground" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('modeling.attributeGroups.search_placeholder', {
              defaultValue: 'Szukaj grup…',
            })}
            className="flex-1 bg-transparent text-[13.5px] outline-none placeholder:text-muted-foreground"
          />
        </div>

        {isLoading ? (
          <p className="px-5 py-10 text-center text-sm text-muted-foreground">{t('app.loading')}</p>
        ) : filtered.length === 0 ? (
          <p className="px-5 py-12 text-center text-sm text-muted-foreground">
            {t('modeling.attributeGroups.empty', {
              defaultValue: 'Brak grup spełniających kryteria.',
            })}
          </p>
        ) : (
          <>
            {systemGroups.length > 0 ? (
              <>
                <SectionDivider
                  withLock={systemSectionHasLockedGroup}
                  label={t('modeling.attributeGroups.section_system_label', {
                    defaultValue: 'System',
                  })}
                />
                <div className="divide-y divide-zinc-50">
                  {systemGroups.map((row) => (
                    <GroupRowItem
                      key={row.id}
                      row={row}
                      locale={i18n.language}
                      usage={usage[row.id]}
                    />
                  ))}
                </div>
              </>
            ) : null}
            {businessGroups.length > 0 ? (
              <>
                <SectionDivider
                  className={systemGroups.length > 0 ? 'mt-1' : ''}
                  label={t('modeling.attributeGroups.section_business_label', {
                    defaultValue: 'Business groups',
                  })}
                />
                <div className="divide-y divide-zinc-50">
                  {businessGroups.map((row) => (
                    <GroupRowItem
                      key={row.id}
                      row={row}
                      locale={i18n.language}
                      usage={usage[row.id]}
                    />
                  ))}
                </div>
              </>
            ) : null}
          </>
        )}
      </Card>
    </div>
  );
}

function SectionDivider({
  label,
  withLock,
  className,
}: {
  label: string;
  withLock?: boolean;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-2 border-b border-zinc-100 px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground',
        className,
      )}
    >
      {withLock ? <Lock className="size-3" /> : null}
      <span>{label}</span>
    </div>
  );
}

function GroupRowItem({
  row,
  locale,
  usage,
}: {
  row: AttributeGroupRow;
  locale: string;
  usage?: UsageResp;
}) {
  const { t } = useTranslation();
  const labelStr = resolveLabel(row.label, locale);
  const descStr = resolveLabel(row.description, locale);
  const color = row.color ?? '#71717a';
  const attrCount = usage?.attributeCount ?? 0;
  const typesUsed = usage?.directlyAttachedTo.objectTypes.length ?? 0;
  const categoriesUsed = usage?.directlyAttachedTo.categories.length ?? 0;
  const isLockedSystemGroup =
    row.systemGroup === true && !isLegacyOptionalSystemGroupCode(row.code);

  return (
    <Link
      to={`/modeling/attribute-groups/${row.id}`}
      className="group grid w-full grid-cols-[44px_1.6fr_1fr_120px_120px_28px] items-center gap-3 px-4 py-3.5 text-left transition hover:bg-zinc-50/70"
    >
      <span
        className="grid size-9 place-items-center rounded-xl text-[16px]"
        style={{ background: `${color}18`, color }}
      >
        {row.icon ?? '📦'}
      </span>
      <span className="flex min-w-0 flex-col">
        <span className="flex items-center gap-2">
          <span className="truncate text-[13.5px] font-semibold tracking-tight">{labelStr}</span>
          {isLockedSystemGroup ? <BuiltInLockBadge /> : null}
        </span>
        {descStr !== '—' ? (
          <span className="truncate text-[11.5px] text-muted-foreground">{descStr}</span>
        ) : null}
      </span>
      <span className="truncate font-mono text-[11.5px] text-muted-foreground">{row.code}</span>
      <span className="text-[12px] tabular-nums">
        <span className="font-medium">{attrCount}</span>{' '}
        <span className="text-muted-foreground">
          {t('modeling.attributeGroups.row_attrs_count_suffix', { defaultValue: 'atrybutów' })}
        </span>
      </span>
      <span className="text-[12px] tabular-nums">
        <span className="text-zinc-700">
          <span className="font-medium">{typesUsed}</span> typy
        </span>
        <span className="text-zinc-300"> · </span>
        <span className="text-zinc-700">
          <span className="font-medium">{categoriesUsed}</span> kat.
        </span>
      </span>
      <span className="flex justify-end">
        <ChevronRight className="size-4 text-zinc-300 group-hover:text-zinc-700" />
      </span>
    </Link>
  );
}

function useGroupsUsage(rows: AttributeGroupRow[]): Record<string, UsageResp> {
  const queries = useQueries({
    queries: rows.map((row) => ({
      queryKey: ['attribute-group-usage', row.id] as const,
      queryFn: () =>
        jsonFetch<UsageResp>(`/api/attribute_groups/${row.id}/usage`, {
          accept: 'application/json',
        }),
      staleTime: 60_000,
    })),
  });
  const map: Record<string, UsageResp> = {};
  for (let i = 0; i < rows.length; i += 1) {
    const data = queries[i]?.data;
    const row = rows[i];
    if (data !== undefined && row !== undefined) map[row.id] = data;
  }
  return map;
}
