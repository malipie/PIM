import { Download, ExternalLink, Link2, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { GatedButton } from '@/components/identity';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import { type AssetMeta, AssetThumb } from './asset-meta';

interface AssetDrawerProps {
  asset: AssetMeta | null;
  onClose: () => void;
  onDeleted: () => void;
}

/**
 * NUI-08 (#1427) — 460px detail drawer (design `Multimedia.html`
 * DetailDrawer). Metadata rows render only fields the backend indexes
 * (format / size / dimensions / folder); approve stays MOCK (no workflow),
 * related-products section is skipped entirely — no reverse index yet
 * (backlog: Project Plan/UI/Retrofit_v2/multimedia-do-oprogramowania.md).
 */
export function AssetDrawer({ asset, onClose, onDeleted }: AssetDrawerProps) {
  const { t } = useTranslation();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);

  if (asset === null) return null;

  const absoluteUrl =
    asset.previewUrl !== null ? new URL(asset.previewUrl, window.location.origin).href : null;

  const copyUrl = (): void => {
    if (absoluteUrl === null || !navigator?.clipboard) return;
    void navigator.clipboard.writeText(absoluteUrl).then(() => {
      toast.success(t('assets.drawer.url_copied', { defaultValue: 'URL skopiowany' }));
    });
  };

  const runDelete = async (): Promise<void> => {
    setDeleting(true);
    try {
      await jsonFetch(`/api/assets/${asset.id}`, { method: 'DELETE', accept: 'application/json' });
      setConfirmDelete(false);
      onClose();
      onDeleted();
    } catch {
      toast.error(t('assets.detail.delete_error'));
    } finally {
      setDeleting(false);
    }
  };

  const rows: Array<{ k: string; v: string; mono?: boolean }> = [
    {
      k: t('assets.drawer.format', { defaultValue: 'Format' }),
      v: asset.ext.toUpperCase(),
      mono: true,
    },
  ];
  if (asset.sizeLabel !== null)
    rows.push({ k: t('assets.drawer.size', { defaultValue: 'Rozmiar' }), v: asset.sizeLabel });
  if (asset.dims !== null)
    rows.push({
      k: t('assets.drawer.dims', { defaultValue: 'Wymiary' }),
      v: `${asset.dims} px`,
      mono: true,
    });
  if (asset.folder !== null)
    rows.push({
      k: t('assets.drawer.folder', { defaultValue: 'Folder' }),
      v: asset.folder,
      mono: true,
    });

  return (
    <div className="fixed inset-0 z-40 flex justify-end">
      <button
        type="button"
        aria-label={t('app.close', { defaultValue: 'Zamknij' })}
        className="absolute inset-0 bg-zinc-900/20 backdrop-blur-[2px]"
        onClick={onClose}
      />
      <div className="relative h-full w-[min(460px,96vw)] overflow-y-auto border-l border-zinc-200 bg-white shadow-2xl">
        <div className="sticky top-0 z-10 flex h-[60px] items-center gap-3 border-b border-zinc-100 bg-white/90 px-5 backdrop-blur">
          <div className="min-w-0 flex-1">
            <div className="truncate text-[13.5px] font-semibold">{asset.filename}</div>
            <div className="text-[11px] text-zinc-500">
              {asset.typeLabel}
              {asset.sizeLabel !== null ? ` · ${asset.sizeLabel}` : ''}
            </div>
          </div>
          <Link
            to={`/assets/${asset.id}`}
            className="grid h-9 w-9 place-items-center rounded-xl text-zinc-500 hover:bg-zinc-100"
            title={t('assets.drawer.open_full', { defaultValue: 'Otwórz pełną stronę' })}
          >
            <ExternalLink className="size-4" />
          </Link>
          <button
            type="button"
            onClick={onClose}
            className="grid h-9 w-9 place-items-center rounded-xl text-zinc-500 hover:bg-zinc-100"
            aria-label={t('app.close', { defaultValue: 'Zamknij' })}
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="space-y-5 p-5">
          <div className="aspect-[4/3] overflow-hidden rounded-2xl border border-zinc-100">
            <AssetThumb asset={asset} iconSize={28} />
          </div>

          <div className="flex items-center gap-2">
            {absoluteUrl !== null ? (
              <a
                href={asset.previewUrl ?? '#'}
                download={asset.filename}
                target="_blank"
                rel="noreferrer"
                className="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-xl bg-zinc-900 text-[12.5px] font-medium text-white hover:bg-zinc-800"
              >
                <Download className="size-3.5" />
                {t('assets.detail.download')}
              </a>
            ) : null}
            <GatedButton
              permission="asset.delete"
              variant="outline"
              size="icon"
              className="h-9 w-9 rounded-xl text-zinc-500 hover:bg-rose-50 hover:text-rose-600"
              onClick={() => setConfirmDelete(true)}
              aria-label={t('assets.detail.delete')}
            >
              <Trash2 className="size-4" />
            </GatedButton>
          </div>

          <div>
            <div className="mb-1.5 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
              {t('assets.drawer.metadata', { defaultValue: 'Metadane' })}
            </div>
            <div className="rounded-2xl border border-zinc-100 bg-zinc-50/70 px-3.5">
              {rows.map((row) => (
                <div
                  key={row.k}
                  className="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0"
                >
                  <div className="text-[12.5px] text-zinc-500">{row.k}</div>
                  <div
                    className={cn(
                      'text-[12.5px] font-medium text-zinc-800',
                      row.mono && 'font-mono',
                    )}
                  >
                    {row.v}
                  </div>
                </div>
              ))}
            </div>
          </div>

          {absoluteUrl !== null ? (
            <div>
              <div className="mb-1.5 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                URL
              </div>
              <button
                type="button"
                onClick={copyUrl}
                title={t('assets.drawer.copy_url', { defaultValue: 'Kopiuj URL' })}
                className="flex w-full items-center gap-2 break-all rounded-xl bg-zinc-900 px-3 py-2.5 text-left font-mono text-[11.5px] text-zinc-100 hover:bg-zinc-800"
              >
                <Link2 className="size-3.5 shrink-0 text-emerald-400" />
                <span className="min-w-0">{absoluteUrl}</span>
              </button>
            </div>
          ) : null}
        </div>
      </div>

      <Dialog open={confirmDelete} onOpenChange={setConfirmDelete}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('assets.detail.delete_confirm_title')}</DialogTitle>
            <DialogDescription>{asset.filename}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setConfirmDelete(false)} disabled={deleting}>
              {t('assets.upload.cancel')}
            </Button>
            <Button variant="destructive" onClick={() => void runDelete()} disabled={deleting}>
              {deleting ? t('assets.detail.saving') : t('assets.detail.delete_confirm_button')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
