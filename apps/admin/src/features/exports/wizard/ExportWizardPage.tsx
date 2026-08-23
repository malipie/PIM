import { Loader2, Play } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate, useSearchParams } from 'react-router';

import { toast } from '@/components/ui/toast';
import { WizardStepper } from '@/components/ui-v2/wizard-stepper';
import type { FilterDsl } from '@/lib/filters/filter-dsl';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';
import { StepColumns } from './steps/StepColumns';
import { StepEntityType } from './steps/StepEntityType';
import { StepScopeFormat } from './steps/StepScopeFormat';
import { StepSummary } from './steps/StepSummary';
import type { ExportEntityType, ExportTargetScope } from './types';
import { type RunError, useRunExport } from './use-run-export';
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
    include_variants?: boolean;
  };
}

function WizardContent() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { state, dispatch } = useWizard();
  const { run, isRunning } = useRunExport();
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
      variantsMode?: 'tree' | 'flat';
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
      includeVariants: listState.variantsMode !== 'tree',
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
          includeVariants: profile.config.include_variants ?? true,
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
  // The run CTA lives in the persistent topbar; it stays greyed until the whole
  // draft is runnable (valid entity, within cap, at least one column) — which
  // only holds once the columns step has been completed.
  const canRun = step1Valid && step2Valid && step3Valid && !isRunning;

  const handleCancel = () => {
    if (state.dirty && !window.confirm(t('exports.wizard.cancel_confirm'))) {
      return;
    }
    void navigate('/integrations/exports/sessions');
  };

  const onRun = async () => {
    try {
      const result = await run(state);
      if (result.kind === 'sync') {
        toast.success(t('exports.wizard.summary.sync_done', { filename: result.filename }));
        void navigate('/integrations/exports/sessions');
        return;
      }
      toast.success(t('exports.wizard.summary.async_started'));
      void navigate('/integrations/exports/sessions', {
        state: { highlightSession: result.sessionId },
      });
    } catch (error) {
      const runError = error as Partial<RunError>;
      if (runError.status === 422) {
        toast.error(t('exports.wizard.summary.error_422', { detail: runError.detail ?? '' }));
        dispatch({ type: 'GO_TO_STEP', step: 2 });
        return;
      }
      if (runError.status === 403) {
        toast.error(t('exports.wizard.summary.error_403'));
        return;
      }
      // A defined status means the server responded but with something we can't
      // download (e.g. an error body leaking through with an ok-ish status) —
      // distinct from a real network failure where fetch rejects (no status).
      if (typeof runError.status === 'number') {
        toast.error(
          t('exports.wizard.summary.error_invalid_response', { detail: runError.detail ?? '' }),
        );
        return;
      }
      toast.error(t('exports.wizard.summary.error_network'));
    }
  };

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="display text-[24px] font-semibold tracking-tight text-ink">
            {t('exports.wizard.title')}
          </h1>
          <p className="mt-1 text-[13px] text-zinc-500">{t('exports.wizard.lead')}</p>
        </div>
        {/* Persistent action bar (ObjectType-style): ghost Anuluj + a navy run
            CTA that stays greyed until the export is runnable. */}
        <div className="flex shrink-0 items-center gap-2">
          <button
            type="button"
            onClick={handleCancel}
            disabled={isRunning}
            className="focus-ring h-10 rounded-xl px-3 text-[12.5px] font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-ink disabled:opacity-50"
          >
            {t('exports.wizard.cancel')}
          </button>
          <button
            type="button"
            disabled={!canRun}
            onClick={() => void onRun()}
            data-testid="run-export"
            className={cn(
              'focus-ring inline-flex h-10 items-center gap-2 rounded-xl px-4 text-[12.5px] font-semibold transition',
              canRun
                ? 'bg-zinc-900 text-white hover:bg-zinc-800'
                : 'cursor-not-allowed bg-zinc-100 text-zinc-500',
            )}
          >
            {isRunning ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : (
              <Play className="size-4" aria-hidden />
            )}
            {t('exports.wizard.summary.run_cta')}
          </button>
        </div>
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
