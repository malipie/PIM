import { Check, Download, FileText, Link2, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useToast } from '@/components/ui/toast';
import { StatusPill } from '@/components/ui-v2/status-pill';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

import {
  absoluteUrl,
  type CatalogProfileRow,
  catalogStatusVariant,
  useDeleteCatalog,
  useMintCatalogToken,
  useRegenerateCatalog,
} from '../api/catalogs';

const TEMPLATE_ICON_TONES: Record<string, string> = {
  sheet: 'bg-sky-50 text-sky-600',
  grid: 'bg-emerald-50 text-emerald-600',
  pricelist: 'bg-violet-50 text-violet-600',
};

/**
 * CPDF-P5-03 — one configured catalog (mirrors the feeds {@link FeedCard}):
 * identity + template badge + status pill, a cache line (last regeneration /
 * page count), and the action footer — Pobierz (mint token → open the pull
 * URL), Regeneruj, Udostępnij (mint token → copy the pull URL, transient
 * "Skopiowano"), Usuń (confirm → delete).
 */
export function CatalogCard({ catalog }: { catalog: CatalogProfileRow }) {
  const { t } = useTranslation();
  const toast = useToast();
  const regenerate = useRegenerateCatalog();
  const mintToken = useMintCatalogToken();
  const remove = useDeleteCatalog();
  const [copied, setCopied] = useState(false);

  const iconTone =
    catalog.status === 'error'
      ? 'bg-brick-50 text-brick-600'
      : catalog.status === 'paused'
        ? 'bg-zinc-100 text-zinc-500'
        : (TEMPLATE_ICON_TONES[catalog.template_kind] ?? 'bg-zinc-100 text-zinc-600');

  function onRegenerate(): void {
    regenerate.mutate(catalog.id, {
      onSuccess: () => toast.success(t('catalogs_pdf.card.regenerate_started')),
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('catalogs_pdf.card.action_error')),
    });
  }

  function onDownload(): void {
    mintToken.mutate(catalog.id, {
      onSuccess: (minted) => window.open(absoluteUrl(minted.url), '_blank', 'noopener,noreferrer'),
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('catalogs_pdf.card.action_error')),
    });
  }

  function onShare(): void {
    mintToken.mutate(catalog.id, {
      onSuccess: (minted) => {
        void navigator.clipboard?.writeText(absoluteUrl(minted.url)).catch(() => {
          /* clipboard blocked — the toast below still confirms the mint */
        });
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
        toast.success(t('catalogs_pdf.card.share_copied'));
      },
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('catalogs_pdf.card.action_error')),
    });
  }

  function onDelete(): void {
    if (!window.confirm(t('catalogs_pdf.card.delete_confirm', { name: catalog.name }))) {
      return;
    }
    remove.mutate(catalog.id, {
      onSuccess: () => toast.success(t('catalogs_pdf.card.delete_success')),
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('catalogs_pdf.card.action_error')),
    });
  }

  const cacheLine =
    catalog.cached_at !== null
      ? t('catalogs_pdf.card.cache_line', {
          date: new Date(catalog.cached_at).toLocaleString('pl-PL'),
          count: catalog.cached_page_count ?? 0,
        })
      : t('catalogs_pdf.card.cache_none');

  return (
    <div className="group rounded-3xl bg-white p-5 soft-shadow transition hover:soft-shadow-lg">
      <div className="flex items-start gap-3">
        <div className={cn('grid h-11 w-11 shrink-0 place-items-center rounded-xl', iconTone)}>
          <FileText className="h-5 w-5" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <div className="text-[15px] font-semibold tracking-tight">{catalog.name}</div>
            <span className="rounded-md border border-zinc-200 px-1.5 py-0.5 font-mono text-[10.5px] uppercase text-zinc-600">
              {t(`catalogs_pdf.template_kind.${catalog.template_kind}`, {
                defaultValue: catalog.template_kind,
              })}
            </span>
          </div>
          <div className="mt-1 flex items-center gap-1.5 text-[11.5px] text-zinc-500">
            <span className="font-mono">{catalog.code}</span>
            {catalog.locale !== null && (
              <>
                <span className="text-zinc-300">·</span>
                <span className="font-mono uppercase">{catalog.locale}</span>
              </>
            )}
          </div>
        </div>
        <StatusPill
          variant={catalogStatusVariant(catalog.status)}
          label={t(`catalogs_pdf.status.${catalog.status}`, { defaultValue: catalog.status })}
        />
      </div>

      <div className="mt-4 flex items-center gap-2 text-[12px] text-zinc-600">
        <FileText className="h-4 w-4 shrink-0 text-zinc-400" aria-hidden />
        <span className="min-w-0 truncate">{cacheLine}</span>
        {catalog.has_token && (
          <span
            className="ml-auto inline-flex shrink-0 items-center gap-1 text-[11px] text-zinc-600"
            title={t('catalogs_pdf.card.has_token')}
          >
            <Link2 className="h-3.5 w-3.5" aria-hidden />
            {t('catalogs_pdf.card.has_token')}
          </span>
        )}
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-3">
        <button
          type="button"
          onClick={onDownload}
          disabled={mintToken.isPending}
          className="flex h-8 items-center gap-1.5 rounded-lg bg-zinc-900 px-3 text-[12px] font-medium text-white hover:bg-zinc-800 disabled:opacity-40"
        >
          <Download className="h-3.5 w-3.5" aria-hidden />
          {t('catalogs_pdf.card.download')}
        </button>
        <button
          type="button"
          onClick={onRegenerate}
          disabled={regenerate.isPending}
          className="flex h-8 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 text-[12px] font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-40"
        >
          <RefreshCw
            className={cn('h-3.5 w-3.5', regenerate.isPending && 'animate-spin')}
            aria-hidden
          />
          {t('catalogs_pdf.card.regenerate')}
        </button>
        <button
          type="button"
          onClick={onShare}
          disabled={mintToken.isPending}
          className="flex h-8 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 text-[12px] font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-40"
        >
          {copied ? (
            <Check className="h-3.5 w-3.5 text-emerald-600" aria-hidden />
          ) : (
            <Link2 className="h-3.5 w-3.5" aria-hidden />
          )}
          {copied ? t('catalogs_pdf.card.share_copied') : t('catalogs_pdf.card.share')}
        </button>
        <button
          type="button"
          onClick={onDelete}
          disabled={remove.isPending}
          aria-label={t('catalogs_pdf.card.delete')}
          title={t('catalogs_pdf.card.delete')}
          className="ml-auto grid h-8 w-8 place-items-center rounded-lg border border-zinc-200 bg-white text-zinc-500 transition hover:bg-brick-50 hover:text-brick-600 disabled:opacity-40"
        >
          <Trash2 className="h-3.5 w-3.5" aria-hidden />
        </button>
      </div>
    </div>
  );
}
