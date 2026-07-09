import { LayoutGrid, LayoutTemplate, Table } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { SelectableCard, SelectableCardGroup } from '@/components/ui-v2/selectable-card';

import type { CatalogTemplateKind } from '../types';
import { useCatalogWizard } from '../wizard-store';

/**
 * CPDF-P5-02 step 2 — archetype pick. Only `sheet` is renderable in the MVP
 * (CatalogTemplateCatalog); `grid` and `pricelist` show as disabled "Wkrótce"
 * cards (module M6). Mirrors the shell's TemplatesTab card set.
 */
export function StepArchetype() {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();

  const select = (templateKind: CatalogTemplateKind) => {
    dispatch({ type: 'SET_TEMPLATE_KIND', templateKind });
  };

  return (
    <div className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
      <h2 className="text-[16px] font-semibold tracking-tight text-ink">
        {t('catalogs_pdf.wizard.archetype_title')}
      </h2>
      <p className="mt-1 text-[13px] text-zinc-500">
        {t('catalogs_pdf.wizard.archetype_subtitle')}
      </p>

      <SelectableCardGroup
        ariaLabel={t('catalogs_pdf.wizard.archetype_group_aria')}
        className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
      >
        <SelectableCard
          icon={<LayoutTemplate className="size-5" aria-hidden />}
          title={t('catalogs_pdf.template_sheet_name')}
          description={t('catalogs_pdf.template_sheet_description')}
          selected={state.templateKind === 'sheet'}
          onSelect={() => select('sheet')}
        />
        <SelectableCard
          icon={<LayoutGrid className="size-5" aria-hidden />}
          title={t('catalogs_pdf.template_grid_name')}
          description={t('catalogs_pdf.template_grid_description')}
          disabled
        />
        <SelectableCard
          icon={<Table className="size-5" aria-hidden />}
          title={t('catalogs_pdf.template_pricelist_name')}
          description={t('catalogs_pdf.template_pricelist_description')}
          disabled
        />
      </SelectableCardGroup>
    </div>
  );
}
