import { jsonFetch } from '@/lib/http';

/**
 * WFL-P4-03 (#2430) — typed client for the workflow tasks surface
 * (WFL-P4-01). "Mine" matches direct assignment or role membership —
 * resolved backend-side.
 */

export interface WorkflowTask {
  id: string;
  title: string;
  type: 'review' | 'fix' | 'request_unpublish' | 'custom';
  status: 'open' | 'done' | 'cancelled';
  object_id: string | null;
  assignee_user_id: string | null;
  assignee_role_code: string | null;
  due_date: string | null;
  comment: string | null;
  context: Record<string, unknown> | null;
  created_at: string;
}

export interface WorkflowTasksPage {
  items: WorkflowTask[];
  next_cursor: string | null;
}

export function fetchWorkflowTasks(options: {
  mine?: boolean;
  status?: 'open' | 'done' | 'cancelled';
  limit?: number;
  before?: string;
}): Promise<WorkflowTasksPage> {
  const params = new URLSearchParams();
  if (options.mine === true) params.set('mine', '1');
  if (options.status !== undefined) params.set('status', options.status);
  params.set('limit', String(options.limit ?? 20));
  if (options.before !== undefined) params.set('before', options.before);

  return jsonFetch<WorkflowTasksPage>(`/api/workflow/tasks?${params.toString()}`);
}

export function actOnWorkflowTask(
  taskId: string,
  action: 'complete' | 'cancel',
): Promise<WorkflowTask> {
  return jsonFetch<WorkflowTask>(`/api/workflow/tasks/${taskId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action }),
  });
}
