import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useSearchParams } from 'react-router';

import { WizardStepper } from '@/components/ui-v2/wizard-stepper';
import type { FilterDsl } from '@/lib/filters/filter-dsl';
import { jsonFetch } from '@/lib/http';
import { StepColumns } from './steps/StepColumns';
import { StepEntityType } from './steps/StepEntityType';
import { StepScopeFormat } from './steps/StepScopeFormat';
import { StepSummary } from './steps/StepSummary';
import type { ExportEntityType, ExportTargetScope } from './types';
import { WizardFooter } from './WizardFooter';
import { useWizard, WizardProvider } from './wizard-store';

/**
 * EXR-09 (#1385) — full-page 4-step export wizard at
 * /integrations/exports/new (screen 2). This ticket ships the shell,
 * the store and step 1; steps 2-4 render placeholders until
 * EXR-10/11/12 fill them in.
 */
export function ExportWizardPage(): React.ReactElement {
  return (
    <WizardProvider>
      <WizardContent />
    </WizardProvider>
  );
}

interface ProfilePayload {
  id: string;
  name: string;
  entity_type: ExportEntityType;
  object_type_id: string | null;
  config: {
    format?: string;
    selected_columns?: string[];
    locales?: string[] | null;
    channels?: string[] | null;
    filter?: FilterDsl | null;
    default_target_scope?: string;
  };
}

function WizardContent() {
  const { t } = useTranslation();
  const { state, dispatch } = useWizard();
  const [searchParams] = useSearchParams();
  const location = useLocation();
  const editProfileId = searchParams.get('profile');
  const scopeParam = searchParams.get('scope');
  const initialisedRef = useRef(false);

  // EXR-14 — list-context entries: ?scope=selected|filter with the
  // selection/DSL travelling via router state (never the URL — hundreds
  // of ids). Missing state → clean wizard.
  useEffect(() => {
    if (scopeParam === null || initialisedRef.current) return;
    const listState = location.state as {
      entityType?: ExportEntityType;
      objectTypeId?: string | null;
      selectedIds?: string[] | null;
      filterDsl?: import('@/lib/filters/filter-dsl').FilterDsl | null;
    } | null;
    if (!listState?.entityType) return;
    initialisedRef.current = true;
    const targetScope: ExportTargetScope =
      scopeParam === 'selected' && (listState.selectedIds?.length ?? 0) > 0
        ? 'selected'
        : (listState.filterDsl ?? null) !== null
          ? 'filter'
          : 'all';
    dispatch({
      type: 'INIT_FROM_LIST',
      entityType: listState.entityType,
      objectTypeId: listState.objectTypeId ?? null,
      selectedIds: targetScope === 'selected' ? (listState.selectedIds ?? []) : null,
      filterDsl: targetScope === 'filter' ? (listState.filterDsl ?? null) : null,
      targetScope,
    });
  }, [scopeParam, location.state, dispatch]);

  // EXR-13 — ?profile={id} opens the wizard prefilled for editing.
  useEffect(() => {
    if (editProfileId === null || initialisedRef.current) return;
    initialisedRef.current = true;
    jsonFetch<ProfilePayload>(`/api/exports/profiles/${editProfileId}`, {
      accept: 'application/json',
    })
      .then((profile) => {
        const filterDsl = profile.config.filter ?? null;
        dispatch({
          type: 'INIT_FROM_PROFILE',
          profileId: profile.id,
          profileName: profile.name,
          entityType: profile.entity_type,
          objectTypeId: profile.object_type_id,
          format:
            profile.config.format === 'csv' || profile.config.format === 'xml'
              ? profile.config.format
              : 'xlsx',
          columns: profile.config.selected_columns ?? [],
          locales: profile.config.locales ?? null,
          channels: profile.config.channels ?? null,
          filterDsl,
          targetScope: filterDsl === null ? 'all' : 'filter',
        });
      })
      .catch(() => {
        // Unknown profile id — fall back to the clean wizard.
      });
  }, [editProfileId, dispatch]);

  const steps = [
    {
      id: 'type',
      label: t('exports.wizard.steps.type'),
      hint: t('exports.wizard.steps.type_hint'),
    },
    {
      id: 'scope',
      label: t('exports.wizard.steps.scope'),
      hint: t('exports.wizard.steps.scope_hint'),
    },
    {
      id: 'columns',
      label: t('exports.wizard.steps.columns'),
      hint: t('exports.wizard.steps.columns_hint'),
    },
    {
      id: 'summary',
      label: t('exports.wizard.steps.summary'),
      hint: t('exports.wizard.steps.summary_hint'),
    },
  ];

  const stepTitles = [
    t('exports.wizard.steps.type'),
    t('exports.wizard.steps.scope'),
    t('exports.wizard.steps.columns'),
    t('exports.wizard.steps.summary'),
  ];

  const step1Valid = state.entityType !== 'custom_module' || state.objectTypeId !== null;
  // EXR-10: soft-cap gate — Dalej blocked while the configuration would
  // exceed the 100k export cap (count=0 stays allowed: headers-only file).
  const step2Valid = state.preflight?.exceeds_cap !== true;
  // EXR-11: at least one column required to proceed to the summary.
  const step3Valid = state.columns.length > 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 className="display text-[24px] font-semibold tracking-tight text-ink">
          {t('exports.wizard.title')}
        </h1>
        <p className="mt-1 text-[13px] text-zinc-500">{t('exports.wizard.lead')}</p>
      </header>

      <WizardStepper
        steps={steps}
        current={state.step}
        onStepClick={(step) => dispatch({ type: 'GO_TO_STEP', step })}
      />

      <div key={state.step}>
        {state.step === 0 && <StepEntityType />}
        {state.step === 1 && <StepScopeFormat />}
        {state.step === 2 && <StepColumns />}
        {state.step === 3 && <StepSummary />}
      </div>

      <WizardFooter
        stepTitle={stepTitles[state.step] ?? ''}
        nextDisabled={
          (state.step === 0 && !step1Valid) ||
          (state.step === 1 && !step2Valid) ||
          (state.step === 2 && !step3Valid)
        }
      />
    </div>
  );
}

export default ExportWizardPage;
