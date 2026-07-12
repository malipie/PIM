import { useCallback, useEffect, useMemo, useState } from 'react';
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
import { objectHref, taskTypeBadge } from '@/features/workflow/task-presentation';
import { WorkflowTaskCard } from '@/features/workflow/WorkflowTaskCard';
import { applyWorkflowTransition } from '@/lib/workflow/api';
import { fetchWorkflowTasks, type WorkflowTask } from '@/lib/workflow/tasks-api';

/**
 * WFL-P4-03 (#2430) + redesign (#2522) — "my work on login" as the rich
 * task grid from the workflow hub, reusing WorkflowTaskCard so the card
 * design lives in one place. Type-filter tabs with counts and inline
 * decisions per task type: a review can be approved/rejected without
 * leaving the dashboard (reject asks for the mandatory comment), while a
 * fix ("Popraw") or an unpublish request ("Rozpatrz") deep-links to the
 * object where the work happens. The widget stays hidden when there is
 * nothing to do — the dashboard stays calm (same rule as the alerts tile).
 */
export function MyTasksCard() {
  const { t } = useTranslation();
  const [tasks, setTasks] = useState<WorkflowTask[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [decision, setDecision] = useState<{
    task: WorkflowTask;
    transition: 'approve' | 'reject';
  } | null>(null);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const reload = useCallback(() => {
    fetchWorkflowTasks({ mine: true, status: 'open', limit: 30 })
      .then((page) => setTasks(page.items))
      .catch(() => setTasks([]))
      .finally(() => setLoaded(true));
  }, []);

  useEffect(() => {
    reload();
  }, [reload]);

  const typeCounts = useMemo(() => {
    const counts = new Map<string, number>();
    for (const task of tasks) counts.set(task.type, (counts.get(task.type) ?? 0) + 1);
    return counts;
  }, [tasks]);

  const visibleTasks = useMemo(
    () => (typeFilter === 'all' ? tasks : tasks.filter((task) => task.type === typeFilter)),
    [tasks, typeFilter],
  );

  const requiresComment = decision?.transition === 'reject';

  const confirmDecision = () => {
    if (decision === null || decision.task.object_id === null) return;
    if (requiresComment && comment.trim() === '') return;
    setSubmitting(true);
    applyWorkflowTransition(
      decision.task.object_id,
      decision.transition,
      comment.trim() || undefined,
    )
      .then(() => {
        toast.success(
          decision.transition === 'approve'
            ? t('workflow.tasks.approved', { defaultValue: 'Zatwierdzono' })
            : t('workflow.tasks.rejected', { defaultValue: 'Odrzucono' }),
        );
        setDecision(null);
        setComment('');
        reload();
      })
      .catch((error: unknown) => {
        toast.error(
          error instanceof Error && error.message !== ''
            ? error.message
            : t('workflow.toast.failed', { defaultValue: 'Operacja nie powiodła się' }),
        );
      })
      .finally(() => setSubmitting(false));
  };

  if (!loaded || tasks.length === 0) {
    return null;
  }

  const renderActions = (task: WorkflowTask) => {
    if (task.type === 'review' && task.object_id !== null) {
      return (
        <>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setComment('');
              setDecision({ task, transition: 'reject' });
            }}
            data-testid={`mytasks-reject-${task.id}`}
          >
            {t('workflow.transition.reject', { defaultValue: 'Odrzuć' })}
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => {
              setComment('');
              setDecision({ task, transition: 'approve' });
            }}
            data-testid={`mytasks-approve-${task.id}`}
          >
            {t('workflow.transition.approve', { defaultValue: 'Zatwierdź' })}
          </Button>
        </>
      );
    }
    if (task.object_id === null) return null;
    const href = objectHref(task.object_kind ?? 'product', task.object_id);
    if (task.type === 'fix') {
      return (
        <Button asChild size="sm" variant="outline" data-testid={`mytasks-fix-${task.id}`}>
          <Link to={href}>{t('workflow.tasks.fix_action', { defaultValue: 'Popraw' })}</Link>
        </Button>
      );
    }
    if (task.type === 'request_unpublish') {
      return (
        <Button asChild size="sm" variant="outline" data-testid={`mytasks-review-${task.id}`}>
          <Link to={href}>{t('workflow.tasks.consider_action', { defaultValue: 'Rozpatrz' })}</Link>
        </Button>
      );
    }
    return null;
  };

  return (
    <div
      className="rounded-2xl border border-line bg-surface p-5 soft-shadow"
      data-testid="my-tasks-card"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center text-sm font-semibold">
          {t('dashboard.my_tasks.title', { defaultValue: 'Moje zadania' })}
          <span className="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
            {tasks.length}
          </span>
        </h2>
        <Link to="/workflow" className="text-xs text-primary hover:underline">
          {t('dashboard.my_tasks.all', { defaultValue: 'Wszystkie' })}
        </Link>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-1" data-testid="mytasks-type-filter">
        {[
          {
            id: 'all',
            label: t('workflow.tasks.filter_all', { defaultValue: 'Wszystkie typy' }),
            count: tasks.length,
          },
          ...[...typeCounts.entries()].map(([type, count]) => {
            const badge = taskTypeBadge(type);
            return { id: type, label: t(badge.key, { defaultValue: badge.fallback }), count };
          }),
        ].map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setTypeFilter(tab.id)}
            data-testid={`mytasks-type-${tab.id}`}
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[13px] font-medium transition ${
              typeFilter === tab.id ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100'
            }`}
          >
            {tab.label}
            <span
              className={`rounded-full px-1.5 text-[11px] ${typeFilter === tab.id ? 'bg-white/20' : 'bg-zinc-200/70'}`}
            >
              {tab.count}
            </span>
          </button>
        ))}
      </div>

      <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        {visibleTasks.map((task) => (
          <WorkflowTaskCard
            key={task.id}
            task={task}
            data-testid={`mytasks-card-${task.id}`}
            actions={renderActions(task)}
          />
        ))}
      </div>

      <Dialog open={decision !== null} onOpenChange={(open) => !open && setDecision(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {decision?.transition === 'approve'
                ? t('workflow.transition.approve', { defaultValue: 'Zatwierdź' })
                : t('workflow.transition.reject', { defaultValue: 'Odrzuć' })}
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
            data-testid="mytasks-comment"
          />
          <DialogFooter>
            <Button variant="ghost" onClick={() => setDecision(null)} disabled={submitting}>
              {t('common.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              onClick={confirmDecision}
              disabled={submitting || (requiresComment && comment.trim() === '')}
              data-testid="mytasks-confirm"
            >
              {t('common.confirm', { defaultValue: 'Potwierdź' })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
