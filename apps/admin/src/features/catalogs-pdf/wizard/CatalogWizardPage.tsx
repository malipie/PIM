import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';

import { WizardStepper } from '@/components/ui-v2/wizard-stepper';
import { jsonFetch } from '@/lib/http';

import { useDefaultObjectType } from '../../catalog/products/use-default-object-type';
import { StepArchetype } from './steps/StepArchetype';
import { StepBranding } from './steps/StepBranding';
import { StepGenerate } from './steps/StepGenerate';
import { StepMapping } from './steps/StepMapping';
import { StepPreview } from './steps/StepPreview';
import { StepScope } from './steps/StepScope';
import { type CatalogResponse, catalogResponseToWizardState } from './use-generate-catalog';
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
  const { state, dispatch, editCatalogId } = useCatalogWizard();
  // The wizard targets products — the built-in product ObjectType id is
  // auto-resolved (same seam the product create page uses; no picker yet).
  const { objectTypeId } = useDefaultObjectType('product');

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

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 className="display text-[24px] font-semibold tracking-tight text-ink">
          {editCatalogId !== null
            ? t('catalogs_pdf.wizard.edit_title', { defaultValue: 'Edytuj katalog' })
            : t('catalogs_pdf.wizard.title')}
        </h1>
        <p className="mt-1 text-[13px] text-zinc-500">{t('catalogs_pdf.wizard.lead')}</p>
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
        {state.step === 5 && <StepGenerate objectTypeId={objectTypeId} />}
      </div>

      {/* On the last step the finish CTA lives inside StepGenerate; the footer
          only carries Anuluj / Wstecz there (finishSlot = null). */}
      <WizardFooter
        stepTitle={steps[state.step]?.label ?? ''}
        nextDisabled={nextDisabled}
        finishSlot={null}
      />
    </div>
  );
}

export default CatalogWizardPage;
