import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { EmptyState } from '@/components/ui-v2/empty-state';
import { actOnWorkflowTask, fetchWorkflowTasks, type WorkflowTask } from '@/lib/workflow/tasks-api';

/**
 * WFL-P4-03 (#2430) — the task inbox (inRiver pattern: "my work" on
 * login): open tasks assigned to me (directly or via my roles) or the
 * tenant-wide list, with type labels, overdue highlighting and inline
 * complete/cancel. Cursor pages older entries.
 */
export function TasksPanel({ mine }: { mine: boolean }) {
  const { t, i18n } = useTranslation();
  const [tasks, setTasks] = useState<WorkflowTask[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const reload = useCallback(() => {
    setLoading(true);
    fetchWorkflowTasks({ mine, status: 'open', limit: 30 })
      .then((page) => {
        setTasks(page.items);
        setCursor(page.next_cursor);
      })
      .catch(() => setTasks([]))
      .finally(() => setLoading(false));
  }, [mine]);

  useEffect(() => {
    reload();
  }, [reload]);

  const act = (task: WorkflowTask, action: 'complete' | 'cancel') => {
    actOnWorkflowTask(task.id, action)
      .then(() => {
        toast.success(
          action === 'complete'
            ? t('workflow.tasks.completed', { defaultValue: 'Zadanie ukończone' })
            : t('workflow.tasks.cancelled', { defaultValue: 'Zadanie anulowane' }),
        );
        reload();
      })
      .catch((error: unknown) => {
        toast.error(
          error instanceof Error && error.message !== ''
            ? error.message
            : t('workflow.toast.failed', { defaultValue: 'Operacja nie powiodła się' }),
        );
      });
  };

  const loadOlder = () => {
    if (cursor === null) return;
    fetchWorkflowTasks({ mine, status: 'open', limit: 30, before: cursor }).then((page) => {
      setTasks((previous) => [...previous, ...page.items]);
      setCursor(page.next_cursor);
    });
  };

  if (tasks.length === 0 && !loading) {
    return (
      <EmptyState
        title={t('workflow.tasks.empty_title', { defaultValue: 'Brak otwartych zadań' })}
        description={t('workflow.tasks.empty_body', {
          defaultValue: 'Zadania z przepływu review pojawią się tutaj automatycznie.',
        })}
      />
    );
  }

  const today = new Date().toISOString().slice(0, 10);

  return (
    <div className="mt-4 flex flex-col gap-2" data-testid="tasks-panel">
      {tasks.map((task) => {
        const overdue = task.due_date !== null && task.due_date < today;
        return (
          <div
            key={task.id}
            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-4 py-3"
            data-testid="task-row"
          >
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium">
                  {t(`workflow.tasks.type.${task.type}`, { defaultValue: task.title })}
                </span>
                {task.due_date !== null && (
                  <span
                    className={
                      overdue
                        ? 'rounded bg-brick-50 px-1.5 py-0.5 text-[10px] font-semibold text-brick-700'
                        : 'rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600'
                    }
                  >
                    {formatDate(task.due_date, i18n.language)}
                  </span>
                )}
                {task.assignee_role_code !== null && (
                  <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600">
                    {task.assignee_role_code}
                  </span>
                )}
              </div>
              {task.comment !== null && (
                <p className="mt-0.5 truncate text-xs text-muted-foreground">{task.comment}</p>
              )}
              {task.object_id !== null && (
                <Link
                  to={`/products/${task.object_id}`}
                  className="text-xs text-primary hover:underline"
                >
                  {t('workflow.tasks.open_object', { defaultValue: 'Otwórz obiekt' })}
                </Link>
              )}
            </div>
            <div className="flex gap-1">
              <Button
                size="sm"
                variant="outline"
                onClick={() => act(task, 'complete')}
                data-testid={`task-complete-${task.id}`}
              >
                {t('workflow.tasks.complete', { defaultValue: 'Ukończ' })}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => act(task, 'cancel')}>
                {t('workflow.tasks.cancel', { defaultValue: 'Anuluj' })}
              </Button>
            </div>
          </div>
        );
      })}
      {cursor !== null && (
        <Button variant="outline" size="sm" onClick={loadOlder}>
          {t('workflow.history.older', { defaultValue: 'Pokaż starsze' })}
        </Button>
      )}
    </div>
  );
}

function formatDate(value: string, locale: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(locale, { dateStyle: 'short' }).format(date);
}
