import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AdvancedFilterPanel } from '@/components/catalog/advanced-filter-panel';
import { useFilterMatchCount } from '@/features/catalog/search/use-filter-match-count';
import type { FilterCondition } from '@/lib/filters/filter-dsl';
import { useFilterDslState } from '@/lib/filters/use-filter-dsl-state';
import { cn } from '@/lib/utils';

import { useCatalogWizard } from '../wizard-store';

/** Common admin locales offered for the catalog render (optional). */
const LOCALE_OPTIONS = ['pl', 'en', 'de'] as const;

/**
 * CPDF-P5-02 step 1 — assortment selection: the AdvancedFilterPanel (shared
 * FilterDSL state) picks which products land in the catalog, plus an optional
 * locale for value resolution. An empty filter means "wszystkie produkty".
 *
 * #2567 — a live count of the selected products gives the operator visible
 * feedback when applying a filter (the panel alone showed nothing happening).
 */
export function StepScope() {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();
  const filter = useFilterDslState(state.filterDsl);

  // Mirror the composed DSL into the wizard store whenever it changes.
  useEffect(() => {
    dispatch({ type: 'SET_FILTER', filterDsl: filter.dsl });
  }, [filter.dsl, dispatch]);

  // #2567 / #2640 — live count of the selected products. Counts from the
  // panel's DRAFT (via onDraftChange), so the number reacts while the operator
  // types — before "Zastosuj filtr" commits the DSL to the wizard store.
  const [draft, setDraft] = useState<{
    conditions: FilterCondition[];
    operator: 'AND' | 'OR';
  }>({ conditions: filter.conditions, operator: filter.matchOperator });
  const { count: matchCount, isLoading: countLoading } = useFilterMatchCount(
    { kind: 'products' },
    draft.conditions,
    draft.operator,
  );

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
          onDraftChange={(conditions, operator) => setDraft({ conditions, operator })}
        />
      </div>

      {/* #2567 — live assortment count so applying a filter shows a result. */}
      <div
        data-testid="catalog-scope-count"
        aria-live="polite"
        className="mt-3 rounded-xl border border-zinc-200 bg-surface-muted px-4 py-2.5 text-[13px]"
      >
        {countLoading && matchCount === undefined ? (
          <span className="text-zinc-500">
            {t('catalogs_pdf.wizard.scope_count_loading', { defaultValue: 'Liczę produkty…' })}
          </span>
        ) : (
          <span className="text-ink">
            <strong className="tabular-nums">{(matchCount ?? 0).toLocaleString('pl-PL')}</strong>{' '}
            {t('catalogs_pdf.wizard.scope_count', {
              count: matchCount ?? 0,
              defaultValue: 'produktów trafi do katalogu',
            })}
          </span>
        )}
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
