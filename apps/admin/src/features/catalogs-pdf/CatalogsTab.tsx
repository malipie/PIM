import { FileText } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState } from '@/components/ui-v2/empty-state';

import { useCatalogKpi, useCatalogs } from './api/catalogs';
import { CatalogCard } from './hub/CatalogCard';
import { CatalogKpiStrip } from './hub/CatalogKpiStrip';

interface CatalogsTabProps {
  /** CTA that opens the create wizard (owned by CPDF-P5-02). */
  onNewCatalog: () => void;
}

/**
 * CPDF-P5-03 — the Catalogs hub tab: a KPI strip plus the catalog card grid.
 * Each card surfaces the last cache state and the Pobierz / Regeneruj /
 * Udostępnij / Usuń actions. With zero catalogs it falls back to the empty
 * state + "Nowy katalog" CTA (kept from the P5-01 shell).
 */
export function CatalogsTab({ onNewCatalog }: CatalogsTabProps) {
  const { t } = useTranslation();
  const catalogsQuery = useCatalogs();
  const kpiQuery = useCatalogKpi();

  const catalogs = catalogsQuery.data ?? [];

  return (
    <div className="space-y-6">
      <CatalogKpiStrip kpi={kpiQuery.data} />

      {catalogs.length > 0 ? (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="font-display text-[16px] font-semibold tracking-tight">
              {t('catalogs_pdf.hub_title')}
            </h2>
            <span className="text-[12px] text-zinc-500">
              {t('catalogs_pdf.hub_subtitle', { count: catalogs.length })}
            </span>
          </div>
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {catalogs.map((catalog) => (
              <CatalogCard key={catalog.id} catalog={catalog} />
            ))}
          </div>
        </>
      ) : (
        <EmptyState
          icon={<FileText className="size-5" aria-hidden />}
          title={t('catalogs_pdf.catalogs_empty_title')}
          description={t('catalogs_pdf.catalogs_empty_description')}
          action={
            <button
              type="button"
              onClick={onNewCatalog}
              className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover"
            >
              {t('catalogs_pdf.new_cta')}
            </button>
          }
        />
      )}
    </div>
  );
}
