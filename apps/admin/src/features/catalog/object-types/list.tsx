import { useList } from '@refinedev/core';
import { Lock, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { ModelingPageHeader } from '@/components/modeling/modeling-page-header';
import { ModelingRow, ModelingSection } from '@/components/modeling/modeling-section';
import { ObjectTypeIcon } from '@/components/modeling/object-type-icon';
import { TopbarCta } from '@/components/ui-v2/topbar-cta';
import { resolveLabel } from '@/features/catalog/attributes/list';
import { usePageActions } from '@/layout/page-actions-context';
import { jsonFetch } from '@/lib/http';

interface ObjectTypeRow {
  id: string;
  code: string;
  kind: string;
  label?: Record<string, string> | string | null;
  builtIn?: boolean;
  codeImmutable?: boolean;
  deletable?: boolean;
  icon?: string | null;
  color?: string | null;
  schemaVersion?: number;
  hierarchical?: boolean;
  hasVariants?: boolean;
  abstract?: boolean;
}

const SECONDARY_LABEL: Record<string, string> = {
  product: 'Products',
  category: 'Categories',
  asset: 'Assets',
};

/**
 * VIEW-01 (#372) — modeling Object Types list. Sections split by
 * `builtIn` flag, badges (`hierarchical` / `variants`) read from BE
 * (no longer heuristics over `kind`), counter for attached groups
 * coming from the new `/attached_groups` endpoint via per-row fetch.
 *
 * The bottom `+` CTA navigates to the wizard route — there is no
 * popup form anymore.
 */
export function ObjectTypesListPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { result, query } = useList<ObjectTypeRow>({
    resource: 'object_types',
    pagination: { mode: 'off' },
  });

  const types = result.data;
  const isLoading = query.isLoading;
  const instanceCounts = useObjectTypeInstanceCounts(types);
  const groupCounts = useObjectTypeGroupCounts(types);

  usePageActions(
    useMemo(
      () => (
        <TopbarCta to="/modeling/object-types/new">
          {t('object_types.create_custom_action', { defaultValue: 'Nowy obiekt' })}
        </TopbarCta>
      ),
      [t],
    ),
  );

  // UX-05 — Category and Asset are system-managed; the operator never
  // models them through this page. Surface only Product among built-ins
  // (plus any future built-in kinds explicitly enabled in modeling).
  const HIDDEN_KINDS = new Set(['category', 'asset']);
  const builtIn = types.filter((row) => row.builtIn !== false && !HIDDEN_KINDS.has(row.kind));
  const custom = types.filter((row) => row.builtIn === false);

  const totalInstances = (rows: ObjectTypeRow[]): number =>
    rows.reduce((acc, row) => acc + (instanceCounts[row.id] ?? 0), 0);

  return (
    <div className="space-y-6">
      <ModelingPageHeader
        title={t('object_types.list_heading', { defaultValue: 'Object Types' })}
      />

      {isLoading ? (
        <div className="rounded-2xl border border-line bg-surface p-10 text-center text-muted-foreground soft-shadow">
          {t('app.loading')}
        </div>
      ) : (
        <>
          <ModelingSection
            label={t('object_types.built_in_label', { defaultValue: 'BUILT-IN (SYSTEM)' })}
            tagline={t('object_types.built_in_tagline', {
              defaultValue: 'fundament PIM-u, używane przez integracje',
            })}
            locked
          >
            {builtIn.length === 0 ? (
              <li className="px-5 py-8 text-center text-[13px] text-muted-foreground">
                {t('object_types.empty')}
              </li>
            ) : (
              builtIn.map((row) => (
                <ObjectTypeListRow
                  key={row.id}
                  row={row}
                  language={i18n.language}
                  instanceCount={instanceCounts[row.id]}
                  groupCount={groupCounts[row.id]}
                />
              ))
            )}
          </ModelingSection>

          <ModelingSection
            label={t('object_types.custom_label', { defaultValue: 'CUSTOM (YOUR ORGANIZATION)' })}
            tagline={t('object_types.custom_tagline', { defaultValue: 'dodane przez ciebie' })}
            summary={
              <span>
                {t('object_types.custom_summary', {
                  defaultValue: '{{kinds}} typów · {{instances}} instancji',
                  kinds: custom.length,
                  instances: totalInstances(custom).toLocaleString('pl-PL'),
                })}
              </span>
            }
          >
            {custom.length === 0 ? (
              <li className="px-5 py-8 text-center text-[13px] text-muted-foreground">
                {t('object_types.custom_empty', {
                  defaultValue: 'Brak custom ObjectTypes — utwórz pierwszy poniżej.',
                })}
              </li>
            ) : (
              custom.map((row) => (
                <ObjectTypeListRow
                  key={row.id}
                  row={row}
                  language={i18n.language}
                  instanceCount={instanceCounts[row.id]}
                  groupCount={groupCounts[row.id]}
                />
              ))
            )}
          </ModelingSection>

          <button
            type="button"
            onClick={() => navigate('/modeling/object-types/new')}
            className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl border border-dashed border-line bg-surface px-6 py-4 text-[14px] font-medium text-ink-2 transition-colors hover:border-orange-500/40 hover:bg-orange-500/5 hover:text-ink"
          >
            <Plus className="size-4" />
            {t('object_types.bottom_cta', {
              defaultValue: '+ Stwórz nowy ObjectType (np. Subskrypcja, Lokalizacja, Wydarzenie)',
            })}
          </button>
        </>
      )}
    </div>
  );
}

interface ObjectTypeListRowProps {
  row: ObjectTypeRow;
  language: string;
  instanceCount: number | undefined;
  groupCount: number | undefined;
}

function ObjectTypeListRow({ row, language, instanceCount, groupCount }: ObjectTypeListRowProps) {
  const { t } = useTranslation();
  const isBuiltIn = row.builtIn !== false;

  return (
    <ModelingRow
      to={`/modeling/object-types/${row.id}`}
      leading={<ObjectTypeIcon kind={row.kind} icon={row.icon} color={row.color} size="md" />}
      title={resolveLabel(row.label, language)}
      code={row.code}
      badges={
        <>
          {isBuiltIn ? (
            <span className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-[10.5px] font-medium uppercase tracking-wide text-muted-foreground">
              <Lock className="size-2.5" />
              {t('object_types.system_badge', { defaultValue: 'system' })}
            </span>
          ) : null}
          {row.hierarchical ? (
            <span className="rounded-md bg-orange-500/10 px-1.5 py-0.5 text-[10.5px] font-medium uppercase tracking-wide text-orange-700">
              hierarchical
            </span>
          ) : null}
          {row.hasVariants ? (
            <span className="rounded-md bg-accent-emerald/10 px-1.5 py-0.5 text-[10.5px] font-medium uppercase tracking-wide text-accent-emerald">
              variants
            </span>
          ) : null}
        </>
      }
      secondaryLabel={SECONDARY_LABEL[row.kind] ?? row.kind}
      metaPrimary={t('object_types.row_groups_count', {
        defaultValue: '{{count}} grup atrybutów',
        count: groupCount ?? 0,
      })}
      metaSecondary={
        <span>
          {instanceCount === undefined ? '—' : instanceCount.toLocaleString('pl-PL')}{' '}
          <span className="font-normal text-muted-foreground">
            {t('object_types.row_instances', { defaultValue: 'instancji' })}
          </span>
        </span>
      }
    />
  );
}

function useObjectTypeInstanceCounts(types: ObjectTypeRow[]): Record<string, number> {
  const [counts, setCounts] = useState<Record<string, number>>({});

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const next: Record<string, number> = {};
      await Promise.all(
        types.map(async (row) => {
          try {
            const usage = await jsonFetch<{ instanceCount: number }>(
              `/api/object_types/${row.id}/usage`,
              { accept: 'application/json' },
            );
            next[row.id] = usage.instanceCount;
          } catch {
            // tolerate 404 / network — leave row's count blank.
          }
        }),
      );
      if (!cancelled) setCounts(next);
    })();
    return () => {
      cancelled = true;
    };
  }, [types]);

  return counts;
}

function useObjectTypeGroupCounts(types: ObjectTypeRow[]): Record<string, number> {
  const [counts, setCounts] = useState<Record<string, number>>({});

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const next: Record<string, number> = {};
      await Promise.all(
        types.map(async (row) => {
          try {
            const groups = await jsonFetch<unknown[]>(
              `/api/object_types/${row.id}/attached_groups`,
              { accept: 'application/json' },
            );
            next[row.id] = Array.isArray(groups) ? groups.length : 0;
          } catch {
            // tolerate failure — fall back to "0 grup" rather than dash.
            next[row.id] = 0;
          }
        }),
      );
      if (!cancelled) setCounts(next);
    })();
    return () => {
      cancelled = true;
    };
  }, [types]);

  return counts;
}
