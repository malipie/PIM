import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { ObjectTypeIcon } from '@/components/modeling/object-type-icon';
import { Button } from '@/components/ui/button';
import type { WorkflowLogEntry } from '@/lib/workflow/api';

import {
  avatarInitial,
  avatarTone,
  kindLabelDefault,
  objectHref,
  relativeTime,
} from './task-presentation';

export interface ReviewRow {
  id: string;
  code: string;
  kind: string;
  label: string;
  completenessPct: number;
  submitted: WorkflowLogEntry | null;
}

interface ReviewQueueRowProps {
  row: ReviewRow;
  selected: boolean;
  canDecide: boolean;
  onToggle: (id: string) => void;
  onDecide: (transition: 'approve' | 'reject', id: string) => void;
}

/**
 * #2677 — single review-queue row. Extracted from ReviewQueuePage (max-lines
 * guard) when the `<table>` was reformatted into an ObjectType-style card
 * list. Responsive: identity / meta / actions sit inline on `sm+` and stack
 * vertically below it so the approve/reject actions never fall off a mobile
 * viewport. Test ids (`review-queue-row`, `queue-approve/reject-{code}`) are
 * unchanged — the workflow specs depend on them.
 */
export function ReviewQueueRow({
  row,
  selected,
  canDecide,
  onToggle,
  onDecide,
}: ReviewQueueRowProps) {
  const { t, i18n } = useTranslation();

  return (
    <li data-testid="review-queue-row" className="transition-colors hover:bg-surface-2/40">
      <div className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:gap-4">
        {/* Object identity — flexible, truncates before it crowds the
            meta/actions. */}
        <div className="flex min-w-0 flex-1 items-start gap-3">
          <input
            type="checkbox"
            checked={selected}
            onChange={() => onToggle(row.id)}
            aria-label={row.label}
            className="mt-1.5 shrink-0"
          />
          <ObjectTypeIcon kind={row.kind} size="sm" className="shrink-0" />
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <Link
                to={objectHref(row.kind, row.id)}
                className="truncate text-[14.5px] font-semibold text-ink hover:underline"
              >
                {row.label}
              </Link>
              <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-600">
                {t(`workflow.kind.${row.kind}`, { defaultValue: kindLabelDefault(row.kind) })}
              </span>
            </div>
            <code className="mt-0.5 block truncate font-mono text-[11.5px] text-muted-foreground">
              {row.code}
            </code>
            {row.submitted?.comment != null && (
              <div className="mt-1 border-l-2 border-zinc-200 pl-2 text-xs italic text-zinc-500">
                {row.submitted.comment}
              </div>
            )}
          </div>
        </div>

        {/* Meta: completeness + submitter. Inline on desktop, wraps under the
            title on mobile. */}
        <div className="flex items-center gap-5 pl-8 sm:pl-0">
          <div className="shrink-0 text-[12.5px] tabular-nums text-muted-foreground">
            <span className="font-medium text-ink">{row.completenessPct}%</span>{' '}
            <span className="hidden sm:inline">
              {t('workflow.queue.col_completeness', { defaultValue: 'Kompletność' })}
            </span>
          </div>
          {row.submitted !== null ? (
            <div className="flex min-w-0 items-center gap-2">
              <span
                className={`grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-semibold ${avatarTone(row.submitted.actor_name ?? row.id)}`}
                aria-hidden="true"
              >
                {avatarInitial(row.submitted.actor_name)}
              </span>
              <div className="min-w-0 leading-tight">
                <div className="truncate text-[13px] text-zinc-700">
                  {row.submitted.actor_name ??
                    t('workflow.queue.system_actor', { defaultValue: 'System' })}
                </div>
                <div className="text-[11px] text-muted-foreground">
                  {relativeTime(row.submitted.created_at, i18n.language)}
                </div>
              </div>
            </div>
          ) : null}
        </div>

        {/* Actions: full width on mobile so they never fall off the viewport;
            auto-width and right-aligned on desktop. */}
        {canDecide ? (
          <div className="flex gap-2 pl-8 sm:shrink-0 sm:justify-end sm:pl-0">
            <Button
              size="sm"
              variant="outline"
              className="flex-1 sm:flex-none"
              onClick={() => onDecide('approve', row.id)}
              data-testid={`queue-approve-${row.code}`}
            >
              {t('workflow.transition.approve', { defaultValue: 'Zatwierdź' })}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              className="flex-1 sm:flex-none"
              onClick={() => onDecide('reject', row.id)}
              data-testid={`queue-reject-${row.code}`}
            >
              {t('workflow.transition.reject', { defaultValue: 'Odrzuć' })}
            </Button>
          </div>
        ) : null}
      </div>
    </li>
  );
}
