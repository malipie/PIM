import { Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

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
import { jsonFetch } from '@/lib/http';

export interface AssetBulkActionsBarProps {
  selectedIds: string[];
  onDeleted: () => void;
  onClearSelection: () => void;
}

/**
 * NUI-08 (#1427) — fixed bottom bulk bar (design `Multimedia.html`).
 * WIRE: Usuń (existing bulk-delete endpoint). MOCK (disabled + tooltip):
 * Pobierz (zip), Przypisz, Zatwierdź — no backend; backlog in
 * Project Plan/UI/Retrofit_v2/multimedia-do-oprogramowania.md.
 */
export function AssetBulkActionsBar({
  selectedIds,
  onDeleted,
  onClearSelection,
}: AssetBulkActionsBarProps) {
  const { t } = useTranslation();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (selectedIds.length === 0) {
    return null;
  }

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await jsonFetch(`/api/assets/bulk-delete`, {
        method: 'POST',
        contentType: 'application/json',
        accept: 'application/json',
        body: { ids: selectedIds },
      });
      onDeleted();
      onClearSelection();
      setConfirmOpen(false);
    } catch {
      setError(t('assets.bulk.delete_error'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <div
        className="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 items-center gap-2 rounded-2xl bg-zinc-900 px-3 py-2 text-white shadow-2xl"
        role="toolbar"
        aria-label={t('assets.bulk.toolbar_aria', { defaultValue: 'Akcje na zaznaczonych' })}
      >
        <span className="num px-2 text-[12.5px] font-medium">
          {selectedIds.length === 1
            ? t('assets.bulk.selected_one')
            : t('assets.bulk.selected_other', { count: selectedIds.length })}
        </span>
        <span className="h-5 w-px bg-white/20" aria-hidden />
        <GatedButton
          anyOf={['asset.delete', 'multimedia.delete']}
          variant="ghost"
          size="sm"
          onClick={() => setConfirmOpen(true)}
          className="h-8 rounded-lg px-3 text-[12.5px] font-medium text-rose-300 hover:bg-white/10 hover:text-rose-200"
        >
          <Trash2 className="size-3.5" />
          {t('assets.bulk.delete')}
        </GatedButton>
        <span className="h-5 w-px bg-white/20" aria-hidden />
        <button
          type="button"
          onClick={onClearSelection}
          aria-label={t('assets.upload.cancel')}
          className="grid h-8 w-8 place-items-center rounded-lg hover:bg-white/10"
        >
          <X className="size-4" />
        </button>
      </div>

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('assets.bulk.delete_confirm_title')}</DialogTitle>
            <DialogDescription>
              {t('assets.bulk.delete_confirm_body', { count: selectedIds.length })}
            </DialogDescription>
          </DialogHeader>
          {error ? (
            <p className="text-sm text-destructive" role="alert">
              {error}
            </p>
          ) : null}
          <DialogFooter>
            <Button variant="ghost" onClick={() => setConfirmOpen(false)} disabled={submitting}>
              {t('assets.upload.cancel')}
            </Button>
            <Button variant="destructive" onClick={submit} disabled={submitting}>
              {submitting ? t('assets.detail.saving') : t('assets.detail.delete_confirm_button')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
