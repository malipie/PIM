import { useTranslation } from 'react-i18next';

import { AttributePicker } from '@/components/catalog/attribute-picker';

import { SHEET_SLOTS } from '../types';
import { useCatalogWizard } from '../wizard-store';

/**
 * CPDF-P5-02 step 4 — field mapping. For each `sheet` slot (title/sku/image/
 * description/price) an attribute picker (#2570 — replaced the free-text code
 * input), prefilled with the template defaults (title→name, sku→sku,
 * image→main_image, …). The BE treats each ref as an attribute code; an empty
 * slot drops from the payload.
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
          const slotLabel = t(`catalogs_pdf.wizard.slot.${slot}`);
          return (
            <div
              key={slot}
              className="grid grid-cols-1 items-center gap-2 sm:grid-cols-[180px_1fr]"
            >
              <span className="text-[13px] font-medium text-ink">{slotLabel}</span>
              <AttributePicker
                value={state.fieldMappings[slot] || null}
                onChange={(next) => dispatch({ type: 'SET_MAPPING', slot, ref: next?.code ?? '' })}
                ariaLabel={slotLabel}
                placeholder={t('catalogs_pdf.wizard.mapping_placeholder')}
              />
            </div>
          );
        })}
      </div>

      <p className="mt-4 text-[12px] text-zinc-500">{t('catalogs_pdf.wizard.mapping_hint')}</p>
    </div>
  );
}
