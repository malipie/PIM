import { useList } from '@refinedev/core';
import { useQueries } from '@tanstack/react-query';
import { ChevronRight, Layers, Search, Shield, Zap } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';

import { Card } from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { TopbarCta } from '@/components/ui-v2/topbar-cta';
import { usePageActions } from '@/layout/page-actions-context';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

interface AttributeRow {
  id: string;
  code: string;
  label: Record<string, string> | string | null;
  type: string;
  required?: boolean;
  localizable?: boolean;
  scopable?: boolean;
  unique?: boolean;
  system?: boolean;
}

interface UsageResponse {
  totalObjects?: number;
  attributeGroups?: Array<{ id: string }>;
  objectTypes?: Array<{ id: string }>;
}

const TYPE_FILTERS = [
  'all',
  'system',
  'text',
  'textarea',
  'identifier',
  'number',
  'boolean',
  'select',
  'multiselect',
  'date',
  'datetime',
  'asset',
  'reference',
  'relation',
  'price',
  'metric',
  'wysiwyg',
  'color',
  'email',
] as const;

type TypeFilter = (typeof TYPE_FILTERS)[number];

/**
 * VIEW-02 (#374) — pixel-perfect rebuild of `AttributesView`
 * (`attributes.jsx:3–112`):
 *   - 26-attribute count caption + "Atrybuty" font-display title +
 *     "Globalna biblioteka pól PIM-u…" description.
 *   - Single Card with sticky search input + chip type filter inline.
 *   - 8-col grid header (Code · nazwa | Type | Flagi | Used in | Groups
 *     | Instances | Wartości).
 *   - Body rows: shield/zap icon, code+name+lock+unique badges, TypeBadge,
 *     i18n/scope chips, usage counts, orange "N wartości" link for
 *     select/multiselect.
 */
export function AttributesListPage() {
  const { t, i18n } = useTranslation();
  const [filter, setFilter] = useState<TypeFilter>('all');
  const [query, setQuery] = useState('');

  usePageActions(
    useMemo(
      () => (
        <TopbarCta to="/modeling/attributes/new">
          {t('attributes.create_action', { defaultValue: 'Nowy atrybut' })}
        </TopbarCta>
      ),
      [t],
    ),
  );
  // DP-04 (#2034) — group filter lives in the URL (`?group=<uuid>`) so a
  // refresh / back-navigation keeps the narrowed view. Filtering happens
  // server-side via `?attributeGroup=` (AttributeGroupFilter, BE).
  const [searchParams, setSearchParams] = useSearchParams();
  const groupFilter = searchParams.get('group') ?? '';

  const { result, query: listQuery } = useList<AttributeRow>({
    resource: 'attributes',
    pagination: { mode: 'off' },
    filters:
      groupFilter !== '' ? [{ field: 'attributeGroup', operator: 'eq', value: groupFilter }] : [],
    // Always refetch on mount + window focus so the list never serves a
    // stale snapshot after the operator creates / edits an attribute on
    // a sibling page (new.tsx, show.tsx, values.tsx) and clicks back.
    // The 5-minute Refine default is a footgun for navigation-driven
    // CRUD flows where one tab can change data the other tab caches.
    queryOptions: { refetchOnMount: 'always', refetchOnWindowFocus: true, staleTime: 0 },
  });

  const { result: groupsResult } = useList<{
    id: string;
    code: string;
    label: Record<string, string> | string | null;
  }>({
    resource: 'attribute_groups',
    pagination: { mode: 'off' },
    queryOptions: { staleTime: 60_000 },
  });
  const groupOptions = groupsResult.data.map((group) => ({
    value: group.id,
    label: resolveLabel(group.label, i18n.language),
    description: group.code,
  }));

  // #2277 — newest first: attribute ids are UUIDv7 (time-ordered), so a
  // descending id sort puts a just-created attribute at the top of the
  // library instead of the tail, where it was easy to miss.
  const attributes = [...result.data].sort((a, b) => (a.id < b.id ? 1 : a.id > b.id ? -1 : 0));
  const isLoading = listQuery.isLoading;
  const usage = useAttributeUsage(attributes);
  const optionCounts = useOptionCounts(attributes);

  const visible = attributes.filter((row) => {
    // System audit attributes (created_at/updated_at/created_by/updated_by)
    // are read-only infrastructure — hide them from the default ("all") and
    // type-filtered views, surfacing them only under the dedicated "system"
    // chip. Refs #1136.
    if (filter === 'system') {
      if (row.system !== true) return false;
    } else if (row.system === true) {
      return false;
    } else if (filter !== 'all' && row.type !== filter) {
      return false;
    }
    if (query.length > 0) {
      const q = query.toLowerCase();
      const code = row.code.toLowerCase();
      const labelStr =
        typeof row.label === 'string'
          ? row.label.toLowerCase()
          : row.label !== null && typeof row.label === 'object'
            ? Object.values(row.label).join(' ').toLowerCase()
            : '';
      if (!code.includes(q) && !labelStr.includes(q)) return false;
    }
    return true;
  });

  return (
    <div className="space-y-6">
      <h1 className="font-display text-[22px] font-semibold tracking-tight">
        {t('attributes.list_title', { defaultValue: 'Attributes' })}
      </h1>

      <Card className="p-2">
        <div className="flex flex-wrap items-center gap-3 border-b border-zinc-100 px-4 py-3">
          <Search className="size-4 text-muted-foreground" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('attributes.search_placeholder', {
              defaultValue: 'Szukaj po code lub nazwie…',
            })}
            className="flex-1 min-w-[180px] bg-transparent text-[13.5px] outline-none placeholder:text-muted-foreground"
          />
          <div className="w-56">
            <Combobox
              options={groupOptions}
              value={groupFilter === '' ? null : groupFilter}
              onChange={(value) => {
                setSearchParams(
                  (prev) => {
                    const next = new URLSearchParams(prev);
                    if (value === null) {
                      next.delete('group');
                    } else {
                      next.set('group', value);
                    }
                    return next;
                  },
                  { replace: true },
                );
              }}
              placeholder={t('attributes.filter.group', { defaultValue: 'Grupa atrybutów' })}
              searchPlaceholder={t('attributes.filter.group_search', {
                defaultValue: 'Szukaj grupy…',
              })}
              emptyText={t('attributes.filter.group_empty', { defaultValue: 'Brak grup' })}
            />
          </div>
          <div className="flex flex-wrap items-center gap-1">
            {TYPE_FILTERS.map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => setFilter(opt)}
                className={cn(
                  'flex h-7 items-center rounded-lg px-2.5 text-[11.5px] font-medium transition',
                  filter === opt
                    ? 'bg-zinc-900 text-white'
                    : 'text-muted-foreground hover:bg-zinc-100',
                )}
              >
                {opt === 'all'
                  ? t('attributes.filter.all', { defaultValue: 'wszystkie' })
                  : opt === 'system'
                    ? t('attributes.filter.system', { defaultValue: 'system' })
                    : t(`attribute_type.${opt}`, { defaultValue: opt })}
              </button>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-[40px_1.4fr_100px_140px_100px_90px_100px_120px] gap-3 border-b border-zinc-100 px-5 py-2.5 text-[10.5px] font-medium uppercase tracking-wider text-muted-foreground">
          <div />
          <div>Code · nazwa</div>
          <div>Type</div>
          <div>Flagi</div>
          <div className="text-right">Used in</div>
          <div className="text-right">Groups</div>
          <div className="text-right">Instances</div>
          <div className="text-right">Wartości</div>
        </div>

        {isLoading ? (
          <p className="px-5 py-8 text-center text-sm text-muted-foreground">{t('app.loading')}</p>
        ) : visible.length === 0 ? (
          <p className="px-5 py-12 text-center text-sm text-muted-foreground">
            {t('attributes.empty', { defaultValue: 'Brak atrybutów spełniających kryteria.' })}
          </p>
        ) : (
          <div className="divide-y divide-zinc-50">
            {visible.map((row) => (
              <AttributeRowItem
                key={row.id}
                row={row}
                locale={i18n.language}
                usage={usage[row.id]}
                optionsCount={optionCounts[row.code]}
              />
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

function AttributeRowItem({
  row,
  locale,
  usage,
  optionsCount,
}: {
  row: AttributeRow;
  locale: string;
  usage?: { typesUsed: number; groupsUsed: number; instancesWith: number };
  optionsCount?: number;
}) {
  const isOpt = row.type === 'select' || row.type === 'multiselect';
  const label = resolveLabel(row.label, locale);
  return (
    <Link
      to={`/modeling/attributes/${row.id}`}
      className="group grid w-full grid-cols-[40px_1.4fr_100px_140px_100px_90px_100px_120px] items-center gap-3 px-5 py-3 transition hover:bg-zinc-50/70"
    >
      <span className="grid h-8 w-8 place-items-center rounded-lg bg-zinc-50 text-zinc-500">
        {row.system ? <Shield className="size-4" /> : <Zap className="size-4" />}
      </span>
      <span className="min-w-0">
        <span className="flex items-center gap-2">
          <span className="truncate font-mono text-[13.5px] font-medium">{row.code}</span>
          {row.unique ? (
            <span className="rounded bg-amber-50 px-1 py-0.5 font-mono text-[10px] text-amber-700">
              unique
            </span>
          ) : null}
        </span>
        <span className="block truncate text-[11.5px] text-muted-foreground">{label}</span>
      </span>
      <span>
        <TypeBadge type={row.type} />
      </span>
      <span className="flex flex-wrap items-center gap-1">
        {row.localizable ? (
          <span className="rounded bg-blue-50 px-1.5 py-0.5 font-mono text-[10px] text-blue-700">
            i18n
          </span>
        ) : null}
        {row.scopable ? (
          <span className="rounded bg-purple-50 px-1.5 py-0.5 font-mono text-[10px] text-purple-700">
            scope
          </span>
        ) : null}
        {!row.localizable && !row.scopable && !row.unique ? (
          <span className="text-[11px] text-zinc-300">—</span>
        ) : null}
      </span>
      <span className="text-right text-[12.5px] tabular-nums">
        <span className="font-medium text-foreground">{usage?.typesUsed ?? 0}</span>
        <span className="text-muted-foreground"> typów</span>
      </span>
      <span className="text-right text-[12.5px] font-medium tabular-nums text-foreground">
        {usage?.groupsUsed ?? 0}
      </span>
      <span className="text-right text-[12.5px] tabular-nums text-foreground/80">
        {(usage?.instancesWith ?? 0).toLocaleString('pl-PL')}
      </span>
      <span className="flex justify-end">
        {isOpt ? (
          <Link
            to={`/modeling/attributes/${row.id}/values`}
            onClick={(e) => e.stopPropagation()}
            className="inline-flex h-7 items-center gap-1.5 rounded-lg bg-orange-50 px-2.5 text-[11.5px] font-medium text-orange-700 transition hover:bg-orange-100"
          >
            <Layers className="size-3 text-orange-500" />
            <span className="tabular-nums">{optionsCount ?? '—'}</span>
            <span className="hidden xl:inline">wartości</span>
          </Link>
        ) : (
          <ChevronRight className="size-4 text-zinc-300 group-hover:text-zinc-500" />
        )}
      </span>
    </Link>
  );
}

function TypeBadge({ type }: { type: string }) {
  const tone =
    type === 'number' || type === 'metric' || type === 'price'
      ? 'bg-accent-blue/10 text-accent-blue'
      : type === 'select' || type === 'multiselect' || type === 'color'
        ? 'bg-accent-amber/10 text-accent-amber'
        : type === 'boolean'
          ? 'bg-accent-emerald/10 text-accent-emerald'
          : type === 'date' || type === 'datetime'
            ? 'bg-accent-sky/10 text-accent-sky'
            : type === 'asset'
              ? 'bg-orange-500/10 text-orange-700'
              : type === 'reference' || type === 'relation'
                ? 'bg-accent-rose/10 text-accent-rose'
                : type === 'identifier'
                  ? 'bg-accent-zinc/10 text-accent-zinc'
                  : 'bg-muted text-muted-foreground';
  return (
    <span className={cn('rounded-md px-2 py-0.5 text-[11px] font-medium uppercase', tone)}>
      {type}
    </span>
  );
}

function useAttributeUsage(
  rows: AttributeRow[],
): Record<string, { typesUsed: number; groupsUsed: number; instancesWith: number }> {
  const queries = useQueries({
    queries: rows.map((row) => ({
      queryKey: ['attribute-usage', row.id] as const,
      queryFn: () => jsonFetch<UsageResponse>(`/api/attributes/${row.id}/usage`),
      staleTime: 60_000,
    })),
  });

  const map: Record<string, { typesUsed: number; groupsUsed: number; instancesWith: number }> = {};
  for (let i = 0; i < rows.length; i += 1) {
    const row = rows[i];
    if (row === undefined) continue;
    const data = queries[i]?.data;
    map[row.id] = {
      typesUsed: data?.objectTypes?.length ?? 0,
      groupsUsed: data?.attributeGroups?.length ?? 0,
      instancesWith: data?.totalObjects ?? 0,
    };
  }
  return map;
}

function useOptionCounts(rows: AttributeRow[]): Record<string, number> {
  const selectRows = rows.filter((r) => r.type === 'select' || r.type === 'multiselect');
  const queries = useQueries({
    queries: selectRows.map((row) => ({
      queryKey: ['attribute-options-count', row.code] as const,
      queryFn: async () => {
        const data = await jsonFetch<{ member?: unknown[] }>(`/api/attributes/${row.code}/options`);
        return data.member?.length ?? 0;
      },
      staleTime: 60_000,
    })),
  });

  const counts: Record<string, number> = {};
  for (let i = 0; i < selectRows.length; i += 1) {
    const data = queries[i]?.data;
    const row = selectRows[i];
    if (typeof data === 'number' && row !== undefined) counts[row.code] = data;
  }
  return counts;
}

export function resolveLabel(
  value: Record<string, string> | string | null | undefined,
  locale: string,
): string {
  if (typeof value === 'string') return value;
  if (value && typeof value === 'object') {
    const lang = locale.split('-')[0] ?? locale;
    return value[lang] ?? value.en ?? value.pl ?? Object.values(value)[0] ?? '—';
  }
  return '—';
}
