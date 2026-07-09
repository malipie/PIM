import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { AdvancedFilterPanel } from '@/components/catalog/advanced-filter-panel';
import { useFilterDslState } from '@/lib/filters/use-filter-dsl-state';
import { cn } from '@/lib/utils';

import { useCatalogWizard } from '../wizard-store';

/** Common admin locales offered for the catalog render (optional). */
const LOCALE_OPTIONS = ['pl', 'en', 'de'] as const;

/**
 * CPDF-P5-02 step 1 — assortment selection: the AdvancedFilterPanel (shared
 * FilterDSL state) picks which products land in the catalog, plus an optional
 * locale for value resolution. An empty filter means "wszystkie produkty".
 */
export function StepScope() {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();
  const filter = useFilterDslState(state.filterDsl);

  // Mirror the composed DSL into the wizard store whenever it changes.
  useEffect(() => {
    dispatch({ type: 'SET_FILTER', filterDsl: filter.dsl });
  }, [filter.dsl, dispatch]);

  return (
    <div className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
      <h2 className="text-[16px] font-semibold tracking-tight text-ink">
        {t('catalogs_pdf.wizard.scope_title')}
      </h2>
      <p className="mt-1 text-[13px] text-zinc-500">{t('catalogs_pdf.wizard.scope_subtitle')}</p>

      <div className="mt-5">
        <AdvancedFilterPanel
          open
          conditions={filter.conditions}
          setConditions={filter.setConditions}
          matchOperator={filter.matchOperator}
          setMatchOperator={filter.setMatchOperator}
          onApply={() => dispatch({ type: 'SET_FILTER', filterDsl: filter.dsl })}
          onClose={() => undefined}
          onClear={filter.clear}
        />
      </div>

      <div className="mt-5 rounded-xl border border-zinc-200 bg-surface-muted p-4">
        <label
          htmlFor="catalog-wizard-locale"
          className="block text-[11px] font-medium tracking-wider text-zinc-500 uppercase"
        >
          {t('catalogs_pdf.wizard.scope_locale_label')}
        </label>
        <select
          id="catalog-wizard-locale"
          value={state.locale ?? ''}
          onChange={(event) => dispatch({ type: 'SET_LOCALE', locale: event.target.value || null })}
          className={cn(
            'focus-ring mt-2 h-10 w-full max-w-xs rounded-xl border border-zinc-200 bg-surface px-3 text-[13px]',
          )}
        >
          <option value="">{t('catalogs_pdf.wizard.scope_locale_default')}</option>
          {LOCALE_OPTIONS.map((locale) => (
            <option key={locale} value={locale}>
              {locale.toUpperCase()}
            </option>
          ))}
        </select>
        <p className="mt-1.5 text-[12px] text-zinc-500">
          {t('catalogs_pdf.wizard.scope_locale_hint')}
        </p>
      </div>
    </div>
  );
}
