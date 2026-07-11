import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { type EditLock, requestUnpublish } from '@/lib/workflow/edit-lock';

/**
 * WFL-P3-03 (#2425) — the PRD §3.8 lock UX: instead of a bare 403 the
 * editor sees WHY the form is locked and WHAT they can do — request an
 * unpublish (published, no rights), expect an auto-unpublish on save
 * (transition.unpublish holders) or nothing (archived is read-only;
 * restore lives on the workflow control next to the status pill).
 */
export function WorkflowLockBanner({ objectId, lock }: { objectId: string; lock: EditLock }) {
  const { t } = useTranslation();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [comment, setComment] = useState('');
  const [sending, setSending] = useState(false);

  if (lock.reason === null) return null;

  const send = () => {
    setSending(true);
    requestUnpublish(objectId, comment.trim() || undefined)
      .then(() => {
        toast.success(
          t('workflow.lock.request_sent', {
            defaultValue: 'Prośba o cofnięcie publikacji wysłana do approverów.',
          }),
        );
        setDialogOpen(false);
        setComment('');
      })
      .catch(() => {
        toast.error(t('workflow.toast.failed', { defaultValue: 'Operacja nie powiodła się' }));
      })
      .finally(() => setSending(false));
  };

  const message = lock.autoUnpublishOnSave
    ? t('workflow.lock.auto_unpublish', {
        defaultValue: 'Produkt jest opublikowany — zapis automatycznie cofnie publikację (draft).',
      })
    : lock.reason === 'published'
      ? t('workflow.lock.published', {
          defaultValue:
            'Produkt opublikowany — edycja wymaga cofnięcia publikacji przez Approvera.',
        })
      : lock.reason === 'review'
        ? t('workflow.lock.review', {
            defaultValue: 'Produkt w przeglądzie — edycja tylko dla recenzentów.',
          })
        : t('workflow.lock.archived', {
            defaultValue: 'Produkt zarchiwizowany — tylko do odczytu (użyj „Przywróć").',
          });

  return (
    <div
      className={
        lock.autoUnpublishOnSave
          ? 'mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900'
          : 'mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-800'
      }
      data-testid="workflow-lock-banner"
      role="status"
    >
      <span>{message}</span>
      {lock.canRequestUnpublish ? (
        <Button
          size="sm"
          variant="outline"
          onClick={() => setDialogOpen(true)}
          data-testid="request-unpublish"
        >
          {t('workflow.lock.request_button', { defaultValue: 'Poproś o cofnięcie publikacji' })}
        </Button>
      ) : null}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t('workflow.lock.request_title', { defaultValue: 'Prośba o cofnięcie publikacji' })}
            </DialogTitle>
            <DialogDescription>
              {t('workflow.lock.request_body', {
                defaultValue: 'Approverzy dostaną powiadomienie z Twoim komentarzem.',
              })}
            </DialogDescription>
          </DialogHeader>
          <Textarea
            value={comment}
            onChange={(event) => setComment(event.target.value)}
            maxLength={2000}
            data-testid="request-unpublish-comment"
          />
          <DialogFooter>
            <Button variant="ghost" onClick={() => setDialogOpen(false)} disabled={sending}>
              {t('common.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button onClick={send} disabled={sending} data-testid="request-unpublish-confirm">
              {t('common.send', { defaultValue: 'Wyślij' })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
