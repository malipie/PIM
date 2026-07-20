import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { lazy, Suspense, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/components/ui/toast';
import { SelectableCard, SelectableCardGroup } from '@/components/ui-v2/selectable-card';
import type { FilterDsl } from '@/lib/filters/filter-dsl';
import { useFilterDslState } from '@/lib/filters/use-filter-dsl-state';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import type { ExportFormat } from '../types';
import { useExportPreflight } from '../use-export-preflight';
import { useWizard } from '../wizard-store';

// EXR-10 CRITICAL REUSE: the export wizard embeds the SAME panel the
// product/universal list uses — no second filter implementation.
const AdvancedFilterPanel = lazy(() =>
  import('@/components/catalog/advanced-filter-panel').then((m) => ({
    default: m.AdvancedFilterPanel,
  })),
);

interface ProfileRow {
  id: string;
  name: string;
  entity_type: string;
  object_type_id: string | null;
  config: {
    format?: string;
    selected_columns?: string[];
    locales?: string[] | null;
    channels?: string[] | null;
    default_target_scope?: string;
    filter?: FilterDsl | null;
  };
}

interface ProfilesResponse {
  items: ProfileRow[];
  total: number;
}

const ACTIVE_FORMATS: Array<{ id: ExportFormat; descKey: string }> = [
  { id: 'xlsx', descKey: 'exports.wizard.format_desc.xlsx' },
  { id: 'csv', descKey: 'exports.wizard.format_desc.csv' },
  { id: 'xml', descKey: 'exports.wizard.format_desc.xml' },
];

/** D1 — visible but disabled format tiles ("wkrótce"), never sent in payloads. */
const SOON_FORMATS = ['json', 'gsheets', 'pdf'] as const;

/**
 * EXR-10 — wizard step 2 (screen 3): saved profile select, format
 * radio-cards (XLSX/CSV active, rest D1-disabled) and the data-scope
 * section embedding the shared AdvancedFilterPanel for product /
 * custom_module entities. Structural entities show a full-structure
 * info card instead. A debounced preflight probe (EXR-07) feeds the
 * "Do wyeksportowania: N" badge and the sync/async routing for step 4.
 */
export function StepScopeFormat() {
  const { t } = useTranslation();
  const { state, dispatch } = useWizard();

  const filterable = state.entityType === 'product' || state.entityType === 'custom_module';
  const selectedMode = state.targetScope === 'selected';

  const filterState = useFilterDslState(state.filterDsl);
  const { dsl } = filterState;

  // Push panel changes into the wizard store (scope follows DSL presence).
  useEffect(() => {
    if (!filterable || selectedMode) return;
    const targetScope = dsl === null ? 'all' : 'filter';
    if (dsl !== state.filterDsl || targetScope !== state.targetScope) {
      dispatch({ type: 'SET_FILTER', filterDsl: dsl, targetScope });
    }
  }, [dsl, filterable, selectedMode, state.filterDsl, state.targetScope, dispatch]);

  const preflight = useExportPreflight({
    entityType: state.entityType,
    objectTypeId: state.objectTypeId,
    targetScope: state.targetScope,
    filterDsl: state.filterDsl,
    selectedIds: state.selectedIds,
    enabled: state.entityType !== 'custom_module' || state.objectTypeId !== null,
  });

  // Surface the preflight result to the footer (cap gate) and step 4.
  useEffect(() => {
    dispatch({ type: 'SET_PREFLIGHT', preflight: preflight.result });
  }, [preflight.result, dispatch]);

  const profilesQuery = useQuery<ProfilesResponse>({
    queryKey: ['exports', 'profiles', 'list'],
    staleTime: 30_000,
    queryFn: () =>
      jsonFetch<ProfilesResponse>('/api/exports/profiles', { accept: 'application/json' }),
  });
  const matchingProfiles = (profilesQuery.data?.items ?? []).filter(
    (profile) =>
      profile.entity_type === state.entityType &&
      (state.entityType !== 'custom_module' || profile.object_type_id === state.objectTypeId),
  );

  const applyProfile = (profileId: string) => {
    const profile = matchingProfiles.find((row) => row.id === profileId);
    if (!profile) return;
    const format =
      profile.config.format === 'csv' || profile.config.format === 'xml'
        ? profile.config.format
        : 'xlsx';
    const filterDsl = profile.config.filter ?? null;
    dispatch({
      type: 'APPLY_PROFILE',
      profileId: profile.id,
      profileName: profile.name,
      format,
      columns: profile.config.selected_columns ?? [],
      locales: profile.config.locales ?? null,
      channels: profile.config.channels ?? null,
      filterDsl,
      targetScope: filterDsl === null ? 'all' : 'filter',
    });
    filterState.setConditions([]);
    toast.success(t('exports.wizard.profile_loaded', { name: profile.name }));
  };

  const count = preflight.result?.count ?? null;
  const exceedsCap = preflight.result?.exceeds_cap === true;

  return (
    <div className="space-y-5">
      <section className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
        <h2 className="text-[16px] font-semibold tracking-tight text-ink">
          {t('exports.wizard.step2_profile_title')}
        </h2>
        <div className="mt-4 max-w-md">
          <label
            htmlFor="wizard-profile-select"
            className="block text-[11px] font-medium tracking-wider text-zinc-500 uppercase"
          >
            {t('exports.wizard.profile_label')}
          </label>
          <select
            id="wizard-profile-select"
            value={state.profileId ?? ''}
            onChange={(event) => {
              if (event.target.value !== '') applyProfile(event.target.value);
            }}
            className="focus-ring mt-2 h-10 w-full rounded-xl border border-zinc-200 bg-surface px-3 text-[13px]"
          >
            <option value="">{t('exports.wizard.profile_placeholder')}</option>
            {matchingProfiles.map((profile) => (
              <option key={profile.id} value={profile.id}>
                {profile.name}
              </option>
            ))}
          </select>
          <p className="mt-1.5 text-[12px] text-zinc-500">{t('exports.wizard.profile_hint')}</p>
        </div>

        <h3 className="mt-6 text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
          {t('exports.wizard.format_label')}
        </h3>
        <SelectableCardGroup
          ariaLabel={t('exports.wizard.format_group_aria')}
          className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
          {[
            ...ACTIVE_FORMATS.map(({ id, descKey }) => (
              <SelectableCard
                key={id}
                title={id.toUpperCase()}
                description={t(descKey)}
                selected={state.format === id}
                onSelect={() => dispatch({ type: 'SET_FORMAT', format: id })}
              />
            )),
            ...SOON_FORMATS.map((id) => (
              <SelectableCard
                key={id}
                title={t(`exports.wizard.format_soon.${id}`)}
                description={t(`exports.wizard.format_soon_desc.${id}`)}
                disabled
              />
            )),
          ]}
        </SelectableCardGroup>
      </section>

      <section className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
        <div className="flex flex-wrap items-center gap-3">
          <h2 className="text-[16px] font-semibold tracking-tight text-ink">
            {t('exports.wizard.step2_scope_title')}
          </h2>
          <span
            data-testid="preflight-badge"
            className={cn(
              'inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11.5px] font-medium',
              exceedsCap ? 'bg-brick-50 text-brick-700' : 'bg-zinc-100 text-zinc-600',
            )}
          >
            {preflight.isLoading && <Loader2 className="size-3 animate-spin" aria-hidden />}
            {exceedsCap
              ? t('exports.wizard.cap_exceeded', {
                  cap: preflight.result?.soft_cap.toLocaleString('pl-PL'),
                })
              : t('exports.wizard.preflight_badge', {
                  count: count === null ? '—' : count.toLocaleString('pl-PL'),
                })}
          </span>
        </div>

        {!filterable && (
          <p className="mt-3 text-[13px] text-zinc-500">
            {t('exports.wizard.structural_scope', {
              count: count ?? 0,
            })}
          </p>
        )}

        {filterable && selectedMode && (
          <div className="mt-4 flex items-center gap-3">
            <span className="inline-flex items-center rounded-md bg-zinc-100 px-2 py-1 text-[12.5px] font-medium text-zinc-700">
              {t('exports.wizard.selected_chip', { count: state.selectedIds?.length ?? 0 })}
            </span>
            <button
              type="button"
              onClick={() => {
                dispatch({ type: 'SET_SELECTED_IDS', selectedIds: null });
                dispatch({ type: 'SET_FILTER', filterDsl: null, targetScope: 'all' });
              }}
              className="focus-ring text-[12.5px] font-medium text-zinc-500 underline-offset-2 hover:text-ink hover:underline"
            >
              {t('exports.wizard.switch_to_filter')}
            </button>
          </div>
        )}

        {filterable && !selectedMode && (
          <div className="mt-4">
            {count === 0 && !preflight.isLoading && (
              <p className="mb-3 text-[12.5px] text-orange-700">
                {t('exports.wizard.empty_set_warning')}
              </p>
            )}
            <Suspense fallback={<div className="h-24 animate-pulse rounded-xl bg-zinc-100" />}>
              <AdvancedFilterPanel
                open
                conditions={filterState.conditions}
                setConditions={filterState.setConditions}
                matchOperator={filterState.matchOperator}
                setMatchOperator={filterState.setMatchOperator}
                scope={filterState.scope}
                setScope={filterState.setScope}
                onApply={() => {}}
                onClose={() => {}}
                onClear={() => filterState.clear()}
                resultCount={count ?? undefined}
              />
            </Suspense>
          </div>
        )}
      </section>
    </div>
  );
}
