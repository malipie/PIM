import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import { SHEET_SLOTS } from '../types';
import { useCatalogWizard } from '../wizard-store';

interface StepGenerateProps {
  /** Built-in product ObjectType id — required to POST /api/catalogs. */
  objectTypeId: string | null;
  /** Submit error lifted from the topbar finish action (create/generate). */
  submitError: string | null;
}

/**
 * CPDF-P5-02 step 6 — summary + name field. The finish CTA ("Utwórz i generuj")
 * lives in the wizard topbar now (#2680); this step only renders the summary,
 * the catalog-name input, and surfaces validation + submit errors inline.
 */
export function StepGenerate({ objectTypeId, submitError }: StepGenerateProps) {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();

  const nameValid = state.name.trim() !== '';

  const summaryRows: Array<{ label: string; value: string }> = [
    { label: t('catalogs_pdf.wizard.summary_name'), value: state.name.trim() || '—' },
    {
      label: t('catalogs_pdf.wizard.summary_archetype'),
      value: t('catalogs_pdf.template_sheet_name'),
    },
    {
      label: t('catalogs_pdf.wizard.summary_locale'),
      value: state.locale ?? t('catalogs_pdf.wizard.summary_locale_default'),
    },
    {
      label: t('catalogs_pdf.wizard.summary_filter'),
      value:
        state.filterDsl === null
          ? t('catalogs_pdf.wizard.summary_filter_all')
          : t('catalogs_pdf.wizard.summary_filter_set'),
    },
    {
      label: t('catalogs_pdf.wizard.summary_mappings'),
      value: SHEET_SLOTS.filter((slot) => state.fieldMappings[slot]?.trim()).length.toString(),
    },
  ];

  return (
    <div className="space-y-5 rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
      <div>
        <h2 className="text-[16px] font-semibold tracking-tight text-ink">
          {t('catalogs_pdf.wizard.generate_title')}
        </h2>
        <p className="mt-1 text-[13px] text-zinc-500">
          {t('catalogs_pdf.wizard.generate_subtitle')}
        </p>
      </div>

      <div className="space-y-1.5">
        <label
          htmlFor="catalog-wizard-name"
          className="block text-[11px] font-medium tracking-wider text-zinc-500 uppercase"
        >
          {t('catalogs_pdf.wizard.generate_name_label')}
        </label>
        <input
          id="catalog-wizard-name"
          type="text"
          value={state.name}
          onChange={(event) => dispatch({ type: 'SET_NAME', name: event.target.value })}
          placeholder={t('catalogs_pdf.wizard.generate_name_placeholder')}
          className={cn(
            'focus-ring h-10 w-full max-w-md rounded-xl border bg-surface px-3 text-[13px]',
            nameValid ? 'border-zinc-200' : 'border-brick-300',
          )}
        />
      </div>

      <dl className="divide-y divide-zinc-100 rounded-xl border border-zinc-200">
        {summaryRows.map((row) => (
          <div key={row.label} className="flex items-center justify-between gap-4 px-4 py-3">
            <dt className="text-[12.5px] text-zinc-500">{row.label}</dt>
            <dd className="text-[13px] font-medium text-ink">{row.value}</dd>
          </div>
        ))}
      </dl>

      {!nameValid && (
        <p className="text-[12.5px] text-brick-600">
          {t('catalogs_pdf.wizard.generate_name_required')}
        </p>
      )}
      {objectTypeId === null && (
        <p className="text-[12.5px] text-brick-600">
          {t('catalogs_pdf.wizard.error_no_object_type')}
        </p>
      )}
      {submitError !== null && (
        <p className="rounded-xl border border-brick-200 bg-brick-50 px-4 py-3 text-[13px] text-brick-700">
          {submitError}
        </p>
      )}
    </div>
  );
}
