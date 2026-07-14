import { LayoutGrid, LayoutTemplate, Table } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { SelectableCard, SelectableCardGroup } from '@/components/ui-v2/selectable-card';
import { StatusPill } from '@/components/ui-v2/status-pill';

/**
 * CPDF-P5-01 — Templates tab. Lists the built-in catalog archetypes. Cards are
 * informational here; all three (sheet / grid / pricelist) are selectable in
 * the create wizard (#2568). Grid/pricelist stay visually muted in this tab
 * (aligning the shell display is a follow-up — see the SelectableCard
 * enabled-state contrast).
 */
export function TemplatesTab() {
  const { t } = useTranslation();

  return (
    <div className="space-y-4">
      <div className="space-y-1">
        <h2 className="text-[15px] font-semibold tracking-tight text-ink">
          {t('catalogs_pdf.templates_title')}
        </h2>
        <p className="max-w-prose text-[12.5px] leading-relaxed text-zinc-500">
          {t('catalogs_pdf.templates_description')}
        </p>
      </div>
      <SelectableCardGroup
        ariaLabel={t('catalogs_pdf.templates_title')}
        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
      >
        <SelectableCard
          icon={<LayoutTemplate className="size-5" aria-hidden />}
          title={t('catalogs_pdf.template_sheet_name')}
          description={t('catalogs_pdf.template_sheet_description')}
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
      <StatusPill variant="success" label={t('catalogs_pdf.template_available')} />
    </div>
  );
}
