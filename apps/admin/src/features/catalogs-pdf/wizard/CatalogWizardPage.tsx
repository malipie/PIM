import { useTranslation } from 'react-i18next';

import { WizardStepper } from '@/components/ui-v2/wizard-stepper';

import { useDefaultObjectType } from '../../catalog/products/use-default-object-type';
import { StepArchetype } from './steps/StepArchetype';
import { StepBranding } from './steps/StepBranding';
import { StepGenerate } from './steps/StepGenerate';
import { StepMapping } from './steps/StepMapping';
import { StepPreview } from './steps/StepPreview';
import { StepScope } from './steps/StepScope';
import { WizardFooter } from './WizardFooter';
import { CatalogWizardProvider, useCatalogWizard } from './wizard-store';

/**
 * CPDF-P5-02 (#2373) — full-page 6-step catalog generation wizard at
 * /catalogs-pdf/new: (1) Zakres, (2) Archetyp, (3) Branding, (4) Mapowanie,
 * (5) Podgląd, (6) Generuj. Mirrors the exports ExportWizardPage shell
 * (WizardStepper + step components + a context store + footer nav).
 */
export function CatalogWizardPage(): React.ReactElement {
  return (
    <CatalogWizardProvider>
      <WizardContent />
    </CatalogWizardProvider>
  );
}

function WizardContent() {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();
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

  // Step 2 (archetype) gates "Dalej" until a renderable archetype is chosen;
  // only `sheet` is renderable in the MVP.
  const nextDisabled = state.step === 1 && state.templateKind !== 'sheet';

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 className="display text-[24px] font-semibold tracking-tight text-ink">
          {t('catalogs_pdf.wizard.title')}
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
