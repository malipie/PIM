import { useQuery } from '@tanstack/react-query';
import { Save } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { WizardStepper } from '@/components/ui-v2/wizard-stepper';
import { httpErrorDetail, jsonFetch } from '@/lib/http';

import { useDefaultObjectType } from '../../catalog/products/use-default-object-type';
import { StepArchetype } from './steps/StepArchetype';
import { StepBranding } from './steps/StepBranding';
import { StepGenerate } from './steps/StepGenerate';
import { StepMapping } from './steps/StepMapping';
import { StepPreview } from './steps/StepPreview';
import { StepScope } from './steps/StepScope';
import {
  type CatalogResponse,
  catalogResponseToWizardState,
  useGenerateCatalog,
} from './use-generate-catalog';
import { WizardFooter } from './WizardFooter';
import { CatalogWizardProvider, useCatalogWizard } from './wizard-store';

/**
 * CPDF-P5-02 (#2373) — full-page 6-step catalog generation wizard at
 * /catalogs-pdf/new: (1) Zakres, (2) Archetyp, (3) Branding, (4) Mapowanie,
 * (5) Podgląd, (6) Generuj. Mirrors the exports ExportWizardPage shell
 * (WizardStepper + step components + a context store + footer nav).
 */
export function CatalogWizardPage(): React.ReactElement {
  // #2566 — /catalogs-pdf/:id/edit opens the wizard prefilled for editing.
  const { id } = useParams<{ id: string }>();
  if (id !== undefined) {
    return <EditCatalogWizard catalogId={id} />;
  }
  return (
    <CatalogWizardProvider>
      <WizardContent />
    </CatalogWizardProvider>
  );
}

function EditCatalogWizard({ catalogId }: { catalogId: string }): React.ReactElement {
  const { t } = useTranslation();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['catalog', catalogId],
    queryFn: () => jsonFetch<CatalogResponse>(`/api/catalogs/${catalogId}`),
  });

  if (isLoading) {
    return (
      <p className="mx-auto max-w-5xl px-6 py-12 text-[13px] text-zinc-500">{t('app.loading')}</p>
    );
  }
  if (isError || data === undefined) {
    return (
      <p className="mx-auto max-w-5xl px-6 py-12 text-[13px] text-brick-600">
        {t('catalogs_pdf.wizard.edit_load_error', {
          defaultValue: 'Nie udało się wczytać katalogu.',
        })}
      </p>
    );
  }
  return (
    <CatalogWizardProvider
      initialState={catalogResponseToWizardState(data)}
      editCatalogId={catalogId}
    >
      <WizardContent />
    </CatalogWizardProvider>
  );
}

function WizardContent() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { state, dispatch, editCatalogId } = useCatalogWizard();
  // The wizard targets products — the built-in product ObjectType id is
  // auto-resolved (same seam the product create page uses; no picker yet).
  const { objectTypeId } = useDefaultObjectType('product');
  const { createAndGenerate, updateCatalog, isRunning } = useGenerateCatalog();
  const [submitError, setSubmitError] = useState<string | null>(null);

  const steps = [
    { id: 'scope', label: t('catalogs_pdf.wizard.steps.scope') },
    { id: 'archetype', label: t('catalogs_pdf.wizard.steps.archetype') },
    { id: 'branding', label: t('catalogs_pdf.wizard.steps.branding') },
    { id: 'mapping', label: t('catalogs_pdf.wizard.steps.mapping') },
    { id: 'preview', label: t('catalogs_pdf.wizard.steps.preview') },
    { id: 'generate', label: t('catalogs_pdf.wizard.steps.generate') },
  ];

  // All three archetypes (sheet / grid / pricelist) are renderable (#2568),
  // and a kind is always selected — no step gates the wizard here.
  const nextDisabled = false;

  const isEdit = editCatalogId !== null;
  // The finish CTA lives in the topbar (like the ObjectType edit header) and is
  // disabled until the draft is submittable. Editing PATCHes the config and does
  // not need the product ObjectType id (only the create+generate path does).
  const canSubmit = state.name.trim() !== '' && (isEdit || objectTypeId !== null) && !isRunning;

  const handleCancel = () => {
    if (state.dirty && !window.confirm(t('catalogs_pdf.wizard.cancel_confirm'))) {
      return;
    }
    void navigate('/catalogs-pdf');
  };

  const handleFinish = async () => {
    setSubmitError(null);
    try {
      // #2566 — edit mode PATCHes the config (regeneration stays a separate hub
      // action); create mode POSTs a new catalog then generates.
      if (editCatalogId !== null) {
        await updateCatalog(editCatalogId, state);
      } else {
        if (objectTypeId === null) {
          setSubmitError(t('catalogs_pdf.wizard.error_no_object_type'));
          return;
        }
        await createAndGenerate(state, objectTypeId);
      }
      void navigate('/catalogs-pdf');
    } catch (err) {
      setSubmitError(httpErrorDetail(err) ?? t('catalogs_pdf.wizard.generate_error'));
    }
  };

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="display text-[24px] font-semibold tracking-tight text-ink">
            {isEdit
              ? t('catalogs_pdf.wizard.edit_title', { defaultValue: 'Edytuj katalog' })
              : t('catalogs_pdf.wizard.title')}
          </h1>
          <p className="mt-1 text-[13px] text-zinc-500">{t('catalogs_pdf.wizard.lead')}</p>
        </div>
        {/* Persistent action bar, mirroring the ObjectType edit topbar: ghost
            Anuluj + a filled primary CTA that stays greyed until submittable. */}
        <div className="flex shrink-0 items-center gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={handleCancel}
            disabled={isRunning}
            className="h-9 rounded-xl px-3 text-[12.5px] text-zinc-600"
          >
            {t('catalogs_pdf.wizard.cancel')}
          </Button>
          <Button
            type="button"
            onClick={() => void handleFinish()}
            disabled={!canSubmit}
            className="h-9 rounded-xl bg-zinc-900 px-4 text-[12.5px] font-medium text-white hover:bg-zinc-800"
          >
            <Save className="size-4" />
            {isRunning
              ? t('catalogs_pdf.wizard.generate_running')
              : isEdit
                ? t('catalogs_pdf.wizard.edit_save_cta', { defaultValue: 'Zapisz zmiany' })
                : t('catalogs_pdf.wizard.generate_cta')}
          </Button>
        </div>
      </header>

      <WizardStepper
        steps={steps}
        current={state.step}
        onStepClick={(step) => dispatch({ type: 'GO_TO_STEP', step })}
      />

      <div key={state.step}>
        {state.step === 0 && <StepScope />}
        {state.step === 1 && <StepArchetype />}
        {state.step === 2 && <StepBranding />}
        {state.step === 3 && <StepMapping />}
        {state.step === 4 && <StepPreview objectTypeId={objectTypeId} />}
        {state.step === 5 && <StepGenerate objectTypeId={objectTypeId} submitError={submitError} />}
      </div>

      {/* The finish CTA lives in the topbar; the footer is step navigation only
          (Wstecz / Dalej), and the last step drops even those — going back is
          handled by the clickable stepper. */}
      <WizardFooter stepTitle={steps[state.step]?.label ?? ''} nextDisabled={nextDisabled} />
    </div>
  );
}

export default CatalogWizardPage;
