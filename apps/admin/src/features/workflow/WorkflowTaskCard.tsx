import { Calendar, ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import type { WorkflowTask } from '@/lib/workflow/tasks-api';

import {
  avatarInitial,
  avatarTone,
  deadlineFraming,
  kindLabelDefault,
  objectHref,
  relativeTime,
  taskTypeBadge,
} from './task-presentation';

/**
 * WFL redesign (#2518) — the shared task card used by the workflow hub
 * ("Moje zadania" / "Wszystkie zadania") and the dashboard widget (#2519):
 * type badge, deadline framing, object title + SKU + kind, the submitter
 * comment and "who submitted", an optional assignee line ("Wszystkie"
 * tab) and a caller-provided action slot (complete/cancel in the hub,
 * approve/reject/… on the dashboard).
 */
interface WorkflowTaskCardProps {
  task: WorkflowTask;
  /** Show the "przypisano: …" line (tenant-wide "Wszystkie zadania" view). */
  showAssignee?: boolean;
  /** Action buttons rendered in the card footer. */
  actions?: ReactNode;
  'data-testid'?: string;
}

export function WorkflowTaskCard({
  task,
  showAssignee = false,
  actions,
  'data-testid': testId = 'task-card',
}: WorkflowTaskCardProps) {
  const { t, i18n } = useTranslation();
  const type = taskTypeBadge(task.type);
  const deadline = deadlineFraming(task.due_date, i18n.language);
  const kind = task.object_kind ?? 'product';
  const title =
    task.object_title ?? task.title ?? t('workflow.tasks.untitled', { defaultValue: 'Obiekt' });

  return (
    <div className="rounded-2xl border border-zinc-200 bg-white p-4" data-testid={testId}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <span className={`rounded-md px-2 py-0.5 text-[12px] font-medium ${type.tone}`}>
            {t(type.key, { defaultValue: type.fallback })}
          </span>
          {deadline !== null ? (
            <span
              className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[12px] ${
                deadline.overdue ? 'bg-rose-50 text-rose-600' : 'bg-zinc-100 text-zinc-500'
              }`}
            >
              <Calendar className="size-3" aria-hidden="true" />
              {t(deadline.key, { defaultValue: deadline.fallback, date: deadline.date })}
            </span>
          ) : null}
        </div>
        {task.object_id !== null ? (
          <Link
            to={objectHref(kind, task.object_id)}
            className="inline-flex shrink-0 items-center gap-1 text-[12px] text-zinc-500 hover:text-zinc-700"
          >
            {t('workflow.tasks.open_object', { defaultValue: 'Otwórz obiekt' })}
            <ExternalLink className="size-3" aria-hidden="true" />
          </Link>
        ) : null}
      </div>

      <h3 className="mt-2 text-[15px] font-semibold text-zinc-900">{title}</h3>
      {task.object_sku !== null ? (
        <div className="mt-0.5 flex items-center gap-1.5 text-[12px] text-zinc-500">
          <span className="font-mono">{task.object_sku}</span>
          <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-600">
            {t(`workflow.kind.${kind}`, { defaultValue: kindLabelDefault(kind) })}
          </span>
        </div>
      ) : null}

      {task.comment !== null ? (
        <p className="mt-2 border-l-2 border-zinc-200 pl-2 text-[13px] italic text-zinc-500">
          „{task.comment}"
        </p>
      ) : null}

      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-3 text-[12px] text-zinc-500">
          {task.created_by_name !== null ? (
            <span className="inline-flex items-center gap-1.5">
              <span
                className={`grid size-5 place-items-center rounded-full text-[10px] font-semibold ${avatarTone(task.created_by_name)}`}
                aria-hidden="true"
              >
                {avatarInitial(task.created_by_name)}
              </span>
              {t('workflow.tasks.submitted_by', {
                defaultValue: 'zgłosił {{name}}',
                name: task.created_by_name,
              })}
              {' · '}
              {relativeTime(task.created_at, i18n.language)}
            </span>
          ) : (
            <span>{relativeTime(task.created_at, i18n.language)}</span>
          )}
          {showAssignee ? (
            <span className="inline-flex items-center gap-1 text-zinc-500">
              {t('workflow.tasks.assigned_to', { defaultValue: 'przypisano' })}:
              <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-600">
                {task.assignee_name ??
                  (task.assignee_role_code !== null
                    ? t('workflow.tasks.role_prefix', {
                        defaultValue: 'Rola: {{role}}',
                        role: task.assignee_role_code,
                      })
                    : t('workflow.tasks.unassigned', { defaultValue: 'nieprzypisane' }))}
              </span>
            </span>
          ) : null}
        </div>
        {actions !== undefined ? <div className="flex items-center gap-1.5">{actions}</div> : null}
      </div>
    </div>
  );
}
