import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { fetchWorkflowTasks, type WorkflowTask } from '@/lib/workflow/tasks-api';

/**
 * WFL-P4-03 (#2430) — "my work on login" (inRiver pattern): open-task
 * count + the five nearest due dates, deep-linking to the workflow hub.
 * Plain read of the tasks API — no dedicated read model until the
 * numbers justify one (ADR-0026 threshold).
 */
export function MyTasksCard() {
  const { t, i18n } = useTranslation();
  const [tasks, setTasks] = useState<WorkflowTask[]>([]);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    fetchWorkflowTasks({ mine: true, status: 'open', limit: 30 })
      .then((page) => setTasks(page.items))
      .catch(() => setTasks([]))
      .finally(() => setLoaded(true));
  }, []);

  if (!loaded || tasks.length === 0) {
    // The dashboard stays calm when there is nothing to do — no empty
    // card competing for attention (same rule as the alerts tile).
    return null;
  }

  const byDue = [...tasks].sort((a, b) => {
    if (a.due_date === null && b.due_date === null) return 0;
    if (a.due_date === null) return 1;
    if (b.due_date === null) return -1;
    return a.due_date.localeCompare(b.due_date);
  });

  return (
    <div
      className="rounded-2xl border border-line bg-surface p-5 soft-shadow"
      data-testid="my-tasks-card"
    >
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">
          {t('dashboard.my_tasks.title', { defaultValue: 'Moje zadania' })}
          <span className="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
            {tasks.length}
          </span>
        </h2>
        <Link to="/workflow" className="text-xs text-primary hover:underline">
          {t('dashboard.my_tasks.all', { defaultValue: 'Wszystkie' })}
        </Link>
      </div>
      <ul className="mt-3 flex flex-col gap-2">
        {byDue.slice(0, 5).map((task) => (
          <li key={task.id} className="flex items-center justify-between gap-2 text-sm">
            <span className="truncate">
              {t(`workflow.tasks.type.${task.type}`, { defaultValue: task.title })}
            </span>
            {task.due_date !== null && (
              <span className="shrink-0 text-xs text-muted-foreground">
                {new Intl.DateTimeFormat(i18n.language, { dateStyle: 'short' }).format(
                  new Date(task.due_date),
                )}
              </span>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
