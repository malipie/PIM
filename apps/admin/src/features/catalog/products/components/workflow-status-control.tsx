import { History } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { StatusPill } from '@/components/ui-v2/status-pill';
import {
  applyWorkflowTransition,
  fetchWorkflowState,
  placePillVariant,
  type WorkflowState,
  type WorkflowTransitionOption,
} from '@/lib/workflow/api';

import { WorkflowHistorySheet } from './workflow-history-sheet';

/**
 * Pakiet E (WFL-P3-01 #2423) — editorial state control on the product
 * header (Pimcore pattern: status chip + transition buttons). Buttons
 * mirror the guard-aware discovery endpoint 1:1 — a blocked transition
 * renders disabled with the blocker reasons in a tooltip (missing
 * permission code / completeness gate with the missing attributes), so
 * the FE never re-implements authorization. Reject requires a comment
 * (PRD §3.8 review loop); every other transition takes an optional one.
 */
export function WorkflowStatusControl({ objectId }: { objectId: string }) {
  const { t } = useTranslation();
  const [state, setState] = useState<WorkflowState | null>(null);
  const [pending, setPending] = useState<WorkflowTransitionOption | null>(null);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);

  const reload = useCallback(() => {
    fetchWorkflowState(objectId)
      .then(setState)
      .catch(() => {
        // Silent: users without workflow.view simply see no control.
        setState(null);
      });
  }, [objectId]);

  useEffect(() => {
    reload();
  }, [reload]);

  if (state === null) {
    return null;
  }

  const requiresComment = pending?.name === 'reject';

  const confirm = () => {
    if (pending === null) return;
    if (requiresComment && comment.trim() === '') return;
    setSubmitting(true);
    applyWorkflowTransition(objectId, pending.name, comment.trim() || undefined)
      .then((result) => {
        toast.success(
          t('workflow.toast.applied', {
            defaultValue: 'Status zmieniony: {{place}}',
            place: t(`workflow.place.${result.current_place}`, {
              defaultValue: result.current_place,
            }),
          }),
        );
        setPending(null);
        setComment('');
        reload();
      })
      .catch((error: unknown) => {
        toast.error(
          error instanceof Error && error.message !== ''
            ? error.message
            : t('workflow.toast.failed', { defaultValue: 'Przejście nie powiodło się' }),
        );
      })
      .finally(() => setSubmitting(false));
  };

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="workflow-status-control">
      <StatusPill
        variant={placePillVariant(state.current_place)}
        label={t(`workflow.place.${state.current_place}`, {
          defaultValue: state.current_place,
        })}
      />
      {state.transitions.map((transition) => {
        const label = t(`workflow.transition.${transition.name}`, {
          defaultValue: transition.name,
        });
        const button = (
          <Button
            key={transition.name}
            variant="outline"
            size="sm"
            disabled={!transition.enabled || submitting}
            onClick={() => {
              setComment('');
              setPending(transition);
            }}
            data-testid={`workflow-transition-${transition.name}`}
          >
            {label}
          </Button>
        );
        if (transition.enabled) {
          return button;
        }
        return (
          <Tooltip key={transition.name}>
            <TooltipTrigger asChild>
              {/* span wrapper: disabled buttons swallow pointer events */}
              <span>{button}</span>
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">
              {transition.blockers.map((blocker) => (
                <p key={blocker.code} className="text-xs">
                  {blocker.message}
                </p>
              ))}
            </TooltipContent>
          </Tooltip>
        );
      })}
      <Button
        variant="ghost"
        size="sm"
        onClick={() => setHistoryOpen(true)}
        aria-label={t('workflow.history.open', { defaultValue: 'Historia workflow' })}
        data-testid="workflow-history-open"
      >
        <History className="size-4" />
      </Button>

      <Dialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t(`workflow.transition.${pending?.name ?? ''}`, {
                defaultValue: pending?.name ?? '',
              })}
            </DialogTitle>
            <DialogDescription>
              {requiresComment
                ? t('workflow.dialog.comment_required', {
                    defaultValue: 'Odrzucenie wymaga komentarza dla autora.',
                  })
                : t('workflow.dialog.comment_optional', {
                    defaultValue: 'Możesz dodać komentarz (opcjonalnie).',
                  })}
            </DialogDescription>
          </DialogHeader>
          <Textarea
            value={comment}
            onChange={(event) => setComment(event.target.value)}
            maxLength={2000}
            placeholder={t('workflow.dialog.comment_placeholder', {
              defaultValue: 'Komentarz…',
            })}
            data-testid="workflow-comment"
          />
          <DialogFooter>
            <Button variant="ghost" onClick={() => setPending(null)} disabled={submitting}>
              {t('common.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              onClick={confirm}
              disabled={submitting || (requiresComment && comment.trim() === '')}
              data-testid="workflow-confirm"
            >
              {t('common.confirm', { defaultValue: 'Potwierdź' })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <WorkflowHistorySheet objectId={objectId} open={historyOpen} onOpenChange={setHistoryOpen} />
    </div>
  );
}
