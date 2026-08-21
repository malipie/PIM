import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/components/ui/dialog';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import type { AssetMeta } from './asset-meta';

interface AssetDeleteDialogProps {
  asset: AssetMeta | null;
  onClose: () => void;
  onDeleted: () => void;
}

/**
 * #2944 — confirmation for the per-tile delete in the media grid.
 *
 * Separate from the bulk bar's dialog on purpose: this one names the file
 * being removed. "Delete 1 item?" and "Delete «cennik-2026.pdf»?" are the
 * same operation but not the same decision.
 */
export function AssetDeleteDialog({ asset, onClose, onDeleted }: AssetDeleteDialogProps) {
  const { t } = useTranslation();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleConfirm = async () => {
    if (asset === null) return;
    setSubmitting(true);
    setError(null);
    try {
      await jsonFetch(`/api/assets/${asset.id}`, { method: 'DELETE' });
      onDeleted();
      onClose();
    } catch (err) {
      setError(
        httpErrorDetail(err) ??
          t('assets.delete_error', { defaultValue: 'Nie udało się usunąć pliku.' }),
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={asset !== null}
      onOpenChange={(next) => {
        if (!next) {
          setError(null);
          onClose();
        }
      }}
    >
      <DialogContent>
        <DialogTitle>
          {t('assets.delete_confirm_title', {
            defaultValue: 'Usunąć „{{name}}”?',
            name: asset?.filename ?? '',
          })}
        </DialogTitle>
        <DialogDescription className="mt-2">
          {t('assets.delete_confirm_body', {
            defaultValue:
              'Plik zniknie z biblioteki i z obiektów, w których jest użyty. Operacja jest nieodwracalna.',
          })}
        </DialogDescription>
        {error !== null ? (
          <p
            role="alert"
            className="mt-4 rounded-md bg-rose-50 px-3 py-2 text-[12.5px] text-rose-700"
          >
            {error}
          </p>
        ) : null}
        <div className="mt-6 flex justify-end gap-2">
          <DialogClose asChild>
            <Button variant="ghost" disabled={submitting}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
          </DialogClose>
          <Button variant="destructive" disabled={submitting} onClick={() => void handleConfirm()}>
            {submitting
              ? t('assets.detail.saving', { defaultValue: 'Usuwanie…' })
              : t('assets.delete_confirm_button', { defaultValue: 'Usuń plik' })}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
