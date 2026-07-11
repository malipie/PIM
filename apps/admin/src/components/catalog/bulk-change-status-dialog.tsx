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
import { jsonFetch } from '@/lib/http';

/**
 * WFL-P3-04 (#2426) — bulk status change from the product grid: the
 * segment-driven workflow idiom (filter -> select -> one transition).
 * The transition list mirrors the object_editorial machine; the
 * backend evaluates topology + RBAC guards + the completeness gate PER
 * ROW (WFL-P1-05), so mixed selections partially apply and the result
 * toast reports success/skipped/locked instead of failing the batch.
 */
const TRANSITIONS = [
  'submit_for_review',
  'publish',
  'approve',
  'reject',
  'unpublish',
  'archive',
  'restore',
] as const;

interface BulkChangeStatusResult {
  success_count: number;
  skipped_count: number;
  error_count: number;
  locked_count?: number;
}

export function BulkChangeStatusDialog({
  ids,
  open,
  onOpenChange,
  onApplied,
}: {
  ids: string[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onApplied: () => void;
}) {
  const { t } = useTranslation();
  const [transition, setTransition] = useState<(typeof TRANSITIONS)[number]>('submit_for_review');
  const [comment, setComment] = useState('');
  const [pending, setPending] = useState(false);

  const requiresComment = transition === 'reject';

  const apply = () => {
    if (requiresComment && comment.trim() === '') return;
    setPending(true);
    jsonFetch<BulkChangeStatusResult>('/api/products/bulk-actions/change_status', {
      method: 'POST',
      body: {
        target_ids: ids,
        payload: {
          transition,
          ...(comment.trim() !== '' ? { comment: comment.trim() } : {}),
        },
      },
    })
      .then((result) => {
        const skipped = result.skipped_count + (result.locked_count ?? 0) + result.error_count;
        toast.success(
          t('products.bulk.status_applied', {
            defaultValue: 'Status zmieniony: {{count}} (pominięte: {{skipped}})',
            count: result.success_count,
            skipped,
          }),
        );
        onOpenChange(false);
        setComment('');
        onApplied();
      })
      .catch((error: unknown) => {
        toast.error(
          error instanceof Error && error.message !== ''
            ? error.message
            : t('workflow.toast.failed', { defaultValue: 'Przejście nie powiodło się' }),
        );
      })
      .finally(() => setPending(false));
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t('products.bulk.change_status_title', {
              defaultValue: 'Zmień status ({{count}})',
              count: ids.length,
            })}
          </DialogTitle>
          <DialogDescription>
            {t('products.bulk.change_status_body', {
              defaultValue:
                'Przejście stosowane per obiekt — wiersze zablokowane przez stan, uprawnienia lub gate kompletności zostaną pominięte i zaraportowane.',
            })}
          </DialogDescription>
        </DialogHeader>
        <label className="text-sm font-medium" htmlFor="bulk-transition-select">
          {t('products.bulk.transition_label', { defaultValue: 'Przejście' })}
        </label>
        <select
          id="bulk-transition-select"
          className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          value={transition}
          onChange={(event) => setTransition(event.target.value as (typeof TRANSITIONS)[number])}
          data-testid="bulk-status-transition"
        >
          {TRANSITIONS.map((name) => (
            <option key={name} value={name}>
              {t(`workflow.transition.${name}`, { defaultValue: name })}
            </option>
          ))}
        </select>
        <Textarea
          value={comment}
          onChange={(event) => setComment(event.target.value)}
          maxLength={2000}
          placeholder={
            requiresComment
              ? t('workflow.dialog.comment_required', {
                  defaultValue: 'Odrzucenie wymaga komentarza dla autora.',
                })
              : t('workflow.dialog.comment_placeholder', { defaultValue: 'Komentarz…' })
          }
          data-testid="bulk-status-comment"
        />
        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={pending}>
            {t('common.cancel', { defaultValue: 'Anuluj' })}
          </Button>
          <Button
            onClick={apply}
            disabled={pending || (requiresComment && comment.trim() === '')}
            data-testid="bulk-status-confirm"
          >
            {t('common.confirm', { defaultValue: 'Potwierdź' })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
