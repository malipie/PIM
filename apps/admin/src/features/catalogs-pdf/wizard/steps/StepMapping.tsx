import { useTranslation } from 'react-i18next';

import { SHEET_SLOTS } from '../types';
import { useCatalogWizard } from '../wizard-store';

/**
 * CPDF-P5-02 step 4 — field mapping. For each `sheet` slot (title/sku/image/
 * description/price) a labelled attribute-code input, prefilled with the
 * template defaults (title→name, sku→sku, image→main_image, …). MVP keeps
 * this as free-text inputs — a full attribute picker is deferred; the BE
 * treats each ref as an attribute code. Empty inputs drop from the payload.
 */
export function StepMapping() {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();

  return (
    <div className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
      <h2 className="text-[16px] font-semibold tracking-tight text-ink">
        {t('catalogs_pdf.wizard.mapping_title')}
      </h2>
      <p className="mt-1 text-[13px] text-zinc-500">{t('catalogs_pdf.wizard.mapping_subtitle')}</p>

      <div className="mt-5 space-y-3">
        {SHEET_SLOTS.map((slot) => {
          const inputId = `catalog-mapping-${slot}`;
          return (
            <div
              key={slot}
              className="grid grid-cols-1 items-center gap-2 sm:grid-cols-[180px_1fr]"
            >
              <label htmlFor={inputId} className="text-[13px] font-medium text-ink">
                {t(`catalogs_pdf.wizard.slot.${slot}`)}
              </label>
              <input
                id={inputId}
                type="text"
                value={state.fieldMappings[slot]}
                onChange={(event) =>
                  dispatch({ type: 'SET_MAPPING', slot, ref: event.target.value })
                }
                placeholder={t('catalogs_pdf.wizard.mapping_placeholder')}
                className="focus-ring h-10 w-full rounded-xl border border-zinc-200 bg-surface px-3 font-mono text-[13px]"
              />
            </div>
          );
        })}
      </div>

      <p className="mt-4 text-[12px] text-zinc-500">{t('catalogs_pdf.wizard.mapping_hint')}</p>
    </div>
  );
}
