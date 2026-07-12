import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { EmptyState } from '@/components/ui-v2/empty-state';
import { actOnWorkflowTask, fetchWorkflowTasks, type WorkflowTask } from '@/lib/workflow/tasks-api';

import { taskTypeBadge } from './task-presentation';
import { WorkflowTaskCard } from './WorkflowTaskCard';

/**
 * WFL-P4-03 (#2430) + redesign (#2518) — the task inbox: open tasks
 * assigned to me (directly / via role / as an approve_reject holder) or
 * the tenant-wide list, shown as rich cards (type badge, deadline,
 * object title + SKU, submitter, assignee) with a type filter and inline
 * complete/cancel. Cursor pages older entries.
 */
export function TasksPanel({ mine }: { mine: boolean }) {
  const { t } = useTranslation();
  const [tasks, setTasks] = useState<WorkflowTask[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [typeFilter, setTypeFilter] = useState<string>('all');

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

  const typeCounts = useMemo(() => {
    const counts = new Map<string, number>();
    for (const task of tasks) counts.set(task.type, (counts.get(task.type) ?? 0) + 1);
    return counts;
  }, [tasks]);
  const visibleTasks = useMemo(
    () => (typeFilter === 'all' ? tasks : tasks.filter((task) => task.type === typeFilter)),
    [tasks, typeFilter],
  );

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

  return (
    <div className="mt-4" data-testid="tasks-panel">
      <div className="mb-3 flex flex-wrap items-center gap-1" data-testid="tasks-type-filter">
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
            data-testid={`tasks-type-${tab.id}`}
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

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        {visibleTasks.map((task) => (
          <WorkflowTaskCard
            key={task.id}
            task={task}
            showAssignee={!mine}
            actions={
              <>
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
              </>
            }
          />
        ))}
      </div>

      {cursor !== null && (
        <Button variant="outline" size="sm" className="mt-3" onClick={loadOlder}>
          {t('workflow.history.older', { defaultValue: 'Pokaż starsze' })}
        </Button>
      )}
    </div>
  );
}
