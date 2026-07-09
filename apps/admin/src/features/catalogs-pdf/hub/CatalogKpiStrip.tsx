import { AlertTriangle, Files, FileText, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import type { CatalogKpi } from '../api/catalogs';

/**
 * CPDF-P5-03 — the catalogs hub KPI strip (mirrors the feeds
 * {@link FeedKpiStrip} styling): 24h regenerations, 24h errors, published
 * pages inventory and 24h item throughput. Undefined KPI (loading / error)
 * renders a graceful "—".
 */
export function CatalogKpiStrip({ kpi }: { kpi: CatalogKpi | undefined }) {
  const { t } = useTranslation();

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile
        label={t('catalogs_pdf.kpi.regenerations')}
        value={formatCount(kpi?.regenerations_24h)}
        icon={<RefreshCw className="h-4.5 w-4.5" aria-hidden />}
        hint={t('catalogs_pdf.kpi.regenerations_hint')}
      />
      <KpiTile
        label={t('catalogs_pdf.kpi.errors')}
        value={formatCount(kpi?.errors_24h)}
        icon={<AlertTriangle className="h-4.5 w-4.5" aria-hidden />}
        hint={t('catalogs_pdf.kpi.errors_hint')}
        tone={kpi && kpi.errors_24h > 0 ? 'error' : 'default'}
      />
      <KpiTile
        label={t('catalogs_pdf.kpi.pages')}
        value={formatCount(kpi?.pages_published)}
        icon={<FileText className="h-4.5 w-4.5" aria-hidden />}
        hint={t('catalogs_pdf.kpi.pages_hint')}
      />
      <KpiTile
        label={t('catalogs_pdf.kpi.items')}
        value={formatCount(kpi?.items_24h)}
        icon={<Files className="h-4.5 w-4.5" aria-hidden />}
        hint={t('catalogs_pdf.kpi.items_hint')}
      />
    </div>
  );
}

interface KpiTileProps {
  label: string;
  value: string;
  icon: React.ReactNode;
  hint: string;
  tone?: 'default' | 'error';
}

function KpiTile({ label, value, icon, hint, tone = 'default' }: KpiTileProps) {
  return (
    <div className="rounded-2xl bg-white p-4 soft-shadow">
      <div className="flex items-start justify-between">
        <div className="min-w-0">
          <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
            {label}
          </div>
          <div className="num mt-1 font-display text-[28px] font-semibold tracking-tight">
            {value}
          </div>
          <div className="mt-1.5 truncate text-[11px] text-zinc-500">{hint}</div>
        </div>
        <div
          className={
            tone === 'error'
              ? 'grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brick-50 text-brick-600'
              : 'grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-zinc-900 text-white'
          }
        >
          {icon}
        </div>
      </div>
    </div>
  );
}

function formatCount(value: number | undefined): string {
  return value === undefined ? '—' : value.toLocaleString('pl-PL');
}
