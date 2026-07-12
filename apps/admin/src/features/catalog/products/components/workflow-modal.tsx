import { History, Shield } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

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
  placePillVariant,
  type WorkflowState,
  type WorkflowTransitionOption,
} from '@/lib/workflow/api';

/**
 * WFL redesign (#2529) — the Workflow modal opened from the product-detail
 * breadcrumb chip. Replaces the inline transition buttons with the approved
 * design: current state, a "podgląd jako rola" preview (which actions each
 * role can perform, per the RBAC map — educational), the guard-aware
 * transition buttons (still discovery-driven for enabled/blockers), the
 * reviewer routing hint (#2521) and the permission note, plus a footer that
 * opens the history panel.
 *
 * Role preview is a static role->transition map (Variant A): it filters the
 * shown actions to what the selected role could do; whether the CURRENT user
 * may actually apply one still comes from the discovery `enabled` flag.
 */
const ROLE_TABS = [
  {
    key: 'submitter',
    labelKey: 'workflow.role.submitter',
    fallback: 'Wprowadzający',
    transitions: ['submit_for_review'],
  },
  {
    key: 'approver',
    labelKey: 'workflow.role.approver',
    fallback: 'Akceptant',
    transitions: ['submit_for_review', 'approve', 'reject', 'unpublish'],
  },
  {
    key: 'admin',
    labelKey: 'workflow.role.admin',
    fallback: 'Administrator',
    transitions: [
      'submit_for_review',
      'publish',
      'approve',
      'reject',
      'unpublish',
      'archive',
      'restore',
    ],
  },
] as const;

interface WorkflowModalProps {
  objectId: string;
  state: WorkflowState;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onApplied: () => void;
  onOpenHistory: () => void;
}

export function WorkflowModal({
  objectId,
  state,
  open,
  onOpenChange,
  onApplied,
  onOpenHistory,
}: WorkflowModalProps) {
  const { t } = useTranslation();
  const [roleKey, setRoleKey] = useState<string>('admin');
  const [pending, setPending] = useState<WorkflowTransitionOption | null>(null);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const requiresComment = pending?.name === 'reject';
  const activeRole = ROLE_TABS.find((role) => role.key === roleKey);
  // Administrator previews the full set (incl. custom-definition transitions
  // whose names are not in the built-in RBAC map); the narrower roles filter
  // to their allowed built-in transitions.
  const visibleTransitions =
    activeRole === undefined || activeRole.key === 'admin'
      ? state.transitions
      : state.transitions.filter((transition) =>
          activeRole.transitions.some((name) => name === transition.name),
        );
  const canSubmit = state.transitions.some((transition) => transition.name === 'submit_for_review');

  const close = (next: boolean) => {
    if (!next) {
      setPending(null);
      setComment('');
    }
    onOpenChange(next);
  };

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
        onApplied();
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
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-w-lg" data-testid="workflow-modal">
        <DialogHeader>
          <DialogTitle>{t('workflow.modal.title', { defaultValue: 'Workflow' })}</DialogTitle>
          <DialogDescription>
            {t('workflow.modal.subtitle', {
              defaultValue: 'Zmień stan wpisu i skieruj zadanie dalej.',
            })}
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <span className="text-[10.5px] font-semibold uppercase tracking-wider text-zinc-500">
              {t('workflow.modal.state', { defaultValue: 'Stan' })}
            </span>
            <StatusPill
              variant={placePillVariant(state.current_place)}
              label={t(`workflow.place.${state.current_place}`, {
                defaultValue: state.current_place,
              })}
            />
          </div>
          <div
            className="inline-flex items-center rounded-lg bg-zinc-100 p-0.5"
            role="tablist"
            data-testid="workflow-role-tabs"
          >
            {ROLE_TABS.map((role) => (
              <button
                key={role.key}
                type="button"
                role="tab"
                aria-selected={roleKey === role.key}
                onClick={() => setRoleKey(role.key)}
                data-testid={`workflow-role-${role.key}`}
                className={`rounded-md px-2.5 py-1 text-[12px] font-medium transition ${
                  roleKey === role.key
                    ? 'bg-white text-zinc-900 shadow-sm'
                    : 'text-zinc-500 hover:text-zinc-700'
                }`}
              >
                {t(role.labelKey, { defaultValue: role.fallback })}
              </button>
            ))}
          </div>
        </div>

        <div>
          <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-wider text-zinc-500">
            {t('workflow.modal.actions', { defaultValue: 'Dostępne akcje' })}
          </p>
          {visibleTransitions.length === 0 ? (
            <p className="text-[13px] text-zinc-500">
              {t('workflow.modal.no_actions', {
                defaultValue: 'Brak akcji dla tej roli w bieżącym stanie.',
              })}
            </p>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              {visibleTransitions.map((transition) => {
                const label = t(`workflow.transition.${transition.name}`, {
                  defaultValue: transition.name,
                });
                const isPrimary =
                  transition.name === 'submit_for_review' || transition.name === 'approve';
                const button = (
                  <Button
                    key={transition.name}
                    variant={isPrimary ? 'default' : 'outline'}
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
                if (transition.enabled) return button;
                return (
                  <Tooltip key={transition.name}>
                    <TooltipTrigger asChild>
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
            </div>
          )}
        </div>

        {pending !== null ? (
          <div
            className="rounded-lg border border-zinc-200 p-3"
            data-testid="workflow-confirm-panel"
          >
            <p className="mb-1.5 text-[13px] font-medium text-zinc-900">
              {t(`workflow.transition.${pending.name}`, { defaultValue: pending.name })}
            </p>
            <p className="mb-2 text-[12px] text-zinc-500">
              {requiresComment
                ? t('workflow.dialog.comment_required', {
                    defaultValue: 'Odrzucenie wymaga komentarza dla autora.',
                  })
                : t('workflow.dialog.comment_optional', {
                    defaultValue: 'Możesz dodać komentarz (opcjonalnie).',
                  })}
            </p>
            <Textarea
              value={comment}
              onChange={(event) => setComment(event.target.value)}
              maxLength={2000}
              placeholder={t('workflow.dialog.comment_placeholder', { defaultValue: 'Komentarz…' })}
              data-testid="workflow-comment"
            />
            <div className="mt-2 flex justify-end gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setPending(null)}
                disabled={submitting}
              >
                {t('common.cancel', { defaultValue: 'Anuluj' })}
              </Button>
              <Button
                size="sm"
                onClick={confirm}
                disabled={submitting || (requiresComment && comment.trim() === '')}
                data-testid="workflow-confirm"
              >
                {t('common.confirm', { defaultValue: 'Potwierdź' })}
              </Button>
            </div>
          </div>
        ) : null}

        <div className="space-y-1.5 rounded-lg bg-zinc-50 p-3 text-[12px] text-zinc-500">
          {state.reviewer !== undefined && canSubmit ? (
            <p data-testid="workflow-reviewer-hint">
              {t('workflow.control.routes_to', { defaultValue: 'Po zgłoszeniu zadanie trafi do:' })}{' '}
              <span className="font-medium text-zinc-700">
                {state.reviewer.type === 'role'
                  ? t('workflow.control.role_label', {
                      defaultValue: 'Rola: {{label}}',
                      label: state.reviewer.label,
                    })
                  : state.reviewer.label}
              </span>
              {' · '}
              <Link
                to="/workflow/settings"
                className="text-indigo-600 underline hover:text-indigo-500"
              >
                {t('workflow.control.change_routing', {
                  defaultValue: 'zmień w Ustawieniach przepływu →',
                })}
              </Link>
            </p>
          ) : null}
          <p className="inline-flex items-center gap-1.5">
            <Shield className="size-3.5 shrink-0" aria-hidden="true" />
            {t('workflow.modal.permission_note', {
              defaultValue: '„Opublikuj" i „Archiwizuj" wymagają uprawnienia Zatwierdzanie.',
            })}
          </p>
        </div>

        <DialogFooter className="sm:justify-between">
          <Button
            variant="ghost"
            size="sm"
            onClick={onOpenHistory}
            data-testid="workflow-history-open"
            className="gap-1.5"
          >
            <History className="size-4" />
            {t('workflow.history.title', { defaultValue: 'Historia workflow' })}
          </Button>
          <Button variant="outline" size="sm" onClick={() => close(false)}>
            {t('common.close', { defaultValue: 'Zamknij' })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
