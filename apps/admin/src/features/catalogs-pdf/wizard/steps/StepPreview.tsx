import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

import { type PreviewResult, useGenerateCatalog } from '../use-generate-catalog';
import { useCatalogWizard } from '../wizard-store';

interface StepPreviewProps {
  /** Built-in product ObjectType id — required for the preview scope. */
  objectTypeId: string | null;
}

/**
 * CPDF-P5-02 step 5 — live HTML preview. A manual "Odśwież podgląd" button
 * POSTs the current draft to /api/catalogs/preview and renders the returned
 * HTML in a sandboxed iframe (srcDoc) for isolation — the BE sanitises it,
 * the sandbox stops any residual script/navigation. Auto-refresh + Mercure
 * progress polish lands in CPDF-P5-04; a manual refresh is enough here.
 */
export function StepPreview({ objectTypeId }: StepPreviewProps) {
  const { t } = useTranslation();
  const { state } = useCatalogWizard();
  const { preview } = useGenerateCatalog();
  const [result, setResult] = useState<PreviewResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const refresh = async () => {
    if (objectTypeId === null) {
      setError(t('catalogs_pdf.wizard.error_no_object_type'));
      return;
    }
    setLoading(true);
    setError(null);
    try {
      setResult(await preview(state, objectTypeId));
    } catch (err) {
      setError(httpErrorDetail(err) ?? t('catalogs_pdf.wizard.preview_error'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-[16px] font-semibold tracking-tight text-ink">
            {t('catalogs_pdf.wizard.preview_title')}
          </h2>
          <p className="mt-1 text-[13px] text-zinc-500">
            {t('catalogs_pdf.wizard.preview_subtitle')}
          </p>
        </div>
        <button
          type="button"
          onClick={() => void refresh()}
          disabled={loading}
          className={cn(
            'focus-ring inline-flex h-10 items-center gap-2 rounded-xl border border-zinc-200 bg-surface px-4 text-[13px] font-medium text-zinc-700 transition enabled:hover:border-zinc-400 disabled:cursor-not-allowed disabled:text-zinc-300',
          )}
        >
          <RefreshCw className={cn('size-4', loading && 'animate-spin')} aria-hidden />
          {t('catalogs_pdf.wizard.preview_refresh')}
        </button>
      </div>

      {error !== null && (
        <p className="mt-4 rounded-xl border border-brick-200 bg-brick-50 px-4 py-3 text-[13px] text-brick-700">
          {error}
        </p>
      )}

      {result !== null && (
        <p className="mt-4 text-[12.5px] text-zinc-500">
          {t('catalogs_pdf.wizard.preview_sample_count', { count: result.sample_count })}
        </p>
      )}

      <div className="mt-3 overflow-hidden rounded-xl border border-zinc-200 bg-white">
        {result === null ? (
          <div className="grid h-64 place-items-center px-6 text-center text-[13px] text-zinc-400">
            {loading
              ? t('catalogs_pdf.wizard.preview_loading')
              : t('catalogs_pdf.wizard.preview_empty')}
          </div>
        ) : (
          <iframe
            title={t('catalogs_pdf.wizard.preview_iframe_title')}
            srcDoc={result.html}
            sandbox=""
            className="h-[560px] w-full border-0"
          />
        )}
      </div>
    </div>
  );
}
