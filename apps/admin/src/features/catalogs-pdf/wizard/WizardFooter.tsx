import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import { WIZARD_STEP_COUNT } from './types';
import { useCatalogWizard } from './wizard-store';

interface WizardFooterProps {
  /** Already-translated short title of the current step (mono line). */
  stepTitle: string;
  /** Validation gate — disables "Dalej" on the current step. */
  nextDisabled?: boolean;
}

/**
 * CPDF-P5-02 — catalog wizard footer: mono `krok N z 6 · <title>` + step
 * navigation. Cancel + the finish CTA moved to the topbar (#2680), so the
 * footer is Wstecz / Dalej only. The last step drops even those — there is no
 * "Dalej" and going back is handled by the clickable stepper.
 */
export function WizardFooter({ stepTitle, nextDisabled = false }: WizardFooterProps) {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();
  const last = state.step === WIZARD_STEP_COUNT - 1;

  return (
    <div className="flex items-center gap-3 border-t border-zinc-100 pt-4">
      <div className="num flex-1 font-mono text-[11.5px] text-zinc-500">
        {t('catalogs_pdf.wizard.step_indicator', {
          step: state.step + 1,
          total: WIZARD_STEP_COUNT,
          title: stepTitle,
        })}
      </div>
      {!last && (
        <>
          <button
            type="button"
            disabled={state.step === 0}
            onClick={() => dispatch({ type: 'GO_TO_STEP', step: state.step - 1 })}
            className="focus-ring h-10 rounded-xl border border-zinc-200 bg-surface px-4 text-[13px] font-medium text-zinc-700 transition enabled:hover:border-zinc-400 disabled:cursor-not-allowed disabled:text-zinc-300"
          >
            {t('catalogs_pdf.wizard.back')}
          </button>
          <button
            type="button"
            disabled={nextDisabled}
            onClick={() => dispatch({ type: 'GO_TO_STEP', step: state.step + 1 })}
            className={cn(
              'focus-ring h-10 rounded-xl px-5 text-[13px] font-semibold transition',
              nextDisabled
                ? 'cursor-not-allowed bg-zinc-100 text-zinc-500'
                : 'bg-cta text-cta-foreground hover:bg-accent-hover',
            )}
          >
            {t('catalogs_pdf.wizard.next')}
          </button>
        </>
      )}
    </div>
  );
}
