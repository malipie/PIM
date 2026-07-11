import {
  CheckCircle2,
  Download,
  FolderTree,
  GitBranch,
  MinusCircle,
  Pencil,
  Trash2,
  X,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { MockBadge } from '@/components/ui/mock-badge';
import { jsonFetch } from '@/lib/http';
import { BulkChangeStatusDialog } from './bulk-change-status-dialog';

import { BulkEditModal } from './bulk-edit-modal';

interface BulkEditJobResponse {
  id: string;
  status: string;
  total: number;
  processed: number;
  errors_count: number;
  first_errors: Array<{ objectId: string; message: string }>;
}

/**
 * UI-02.11 (#301) — sticky bulk actions toolbar wired to the new
 * `POST /api/products/bulk-edit` endpoint (UI-02.3).
 *
 * Slice scope:
 * - Sticky-bottom layout when ≥1 product selected.
 * - Toggle enable / disable through `bulk-edit` endpoint with the
 *   `toggle_enabled` operation — sync execution covers MVP volumes.
 * - Show-only-selected toggle controls upstream filter via callback.
 * - Per-job error preview pinned under the toolbar.
 *
 * Async progress modal (Mercure subscription) ships once the backend
 * Faza 1 dispatch lands; the response shape is already job-aware so
 * this component will re-render the same fields.
 */
export function BulkActionsToolbar({
  ids,
  showSelectedOnly,
  onToggleShowSelectedOnly,
  onCleared,
}: {
  ids: string[];
  showSelectedOnly: boolean;
  onToggleShowSelectedOnly: (next: boolean) => void;
  onCleared: () => void;
}) {
  const { t } = useTranslation();
  const [isPending, setIsPending] = useState(false);
  const [lastJob, setLastJob] = useState<BulkEditJobResponse | null>(null);
  const [showEditModal, setShowEditModal] = useState(false);
  const [showStatusModal, setShowStatusModal] = useState(false);

  if (ids.length === 0) return null;

  const run = async (
    operation: 'toggle_enabled',
    payload: Record<string, unknown>,
  ): Promise<void> => {
    setIsPending(true);
    setLastJob(null);
    try {
      const job = await jsonFetch<BulkEditJobResponse>('/api/products/bulk-edit', {
        method: 'POST',
        body: { operation, product_ids: ids, payload },
      });
      setLastJob(job);
      if (job.errors_count === 0) {
        onCleared();
      }
    } finally {
      setIsPending(false);
    }
  };

  const handleDelete = async (): Promise<void> => {
    if (
      !window.confirm(
        t('products.bulk.confirm_delete', {
          count: ids.length,
          defaultValue: 'Delete {{count}} products?',
        }),
      )
    ) {
      return;
    }
    setIsPending(true);
    try {
      for (const id of ids) {
        await jsonFetch(`/api/products/${id}`, { method: 'DELETE' });
      }
      onCleared();
    } finally {
      setIsPending(false);
    }
  };

  return (
    <div
      className="sticky bottom-4 z-30 mx-auto flex max-w-4xl flex-col gap-2 rounded-2xl border border-line bg-surface px-4 py-3 soft-shadow-lg"
      data-testid="bulk-actions-toolbar"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">
          {t('products.bulk.selected', {
            count: ids.length,
            defaultValue: '{{count}} selected',
          })}
        </span>
        <Button type="button" variant="ghost" size="sm" onClick={onCleared} disabled={isPending}>
          <X className="size-4" />
          {t('products.bulk.clear', { defaultValue: 'Clear' })}
        </Button>
        <label className="ml-auto inline-flex cursor-pointer items-center gap-1 text-xs">
          <input
            type="checkbox"
            checked={showSelectedOnly}
            onChange={(e) => onToggleShowSelectedOnly(e.target.checked)}
            className="size-3"
          />
          {t('products.bulk.show_selected_only', { defaultValue: 'Show selected only' })}
        </label>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setShowEditModal(true)}
          disabled={isPending}
        >
          <Pencil className="size-4" />
          {t('products.bulk.edit_attribute', { defaultValue: 'Bulk edit attribute' })}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => run('toggle_enabled', { enabled: true })}
          disabled={isPending}
        >
          <CheckCircle2 className="size-4" />
          {t('products.bulk.enable', { defaultValue: 'Enable' })}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => run('toggle_enabled', { enabled: false })}
          disabled={isPending}
        >
          <MinusCircle className="size-4" />
          {t('products.bulk.disable', { defaultValue: 'Disable' })}
        </Button>
        {/* WFL-P3-04 (#2426) — transitions per row through the state
            machine (guards + gate evaluated backend-side). */}
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setShowStatusModal(true)}
          disabled={isPending}
          data-testid="bulk-change-status"
        >
          <GitBranch className="size-4" />
          {t('products.bulk.change_status', { defaultValue: 'Zmień status' })}
        </Button>
        {/* MOCK: bulk category change — wymaga rozszerzenia POST /api/products/bulk-edit
            o operation 'change_category' (#TBD). Patrz Project Plan/UI/Wdrozenie_grafiki/produkty-do-oprogramowania.md. */}
        <span className="inline-flex items-center gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled
            aria-disabled="true"
            title={t('products.bulk.change_category_disabled', {
              defaultValue: 'Mock — wymaga operation change_category w /bulk-edit',
            })}
          >
            <FolderTree className="size-4" />
            {t('products.bulk.change_category', { defaultValue: 'Zmień kategorię' })}
            <kbd className="ml-1.5 rounded bg-muted px-1 py-0.5 font-mono text-[10px] text-muted-foreground">
              K
            </kbd>
          </Button>
          <MockBadge
            tooltip={t('products.bulk.change_category_disabled', {
              defaultValue: 'Mock — wymaga operation change_category w /bulk-edit',
            })}
          />
        </span>
        {/* MOCK: bulk export CSV/XLSX — wymaga GET /api/products/export?ids=...&format=csv (#TBD). */}
        <span className="inline-flex items-center gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled
            aria-disabled="true"
            title={t('products.bulk.export_disabled', {
              defaultValue: 'Mock — wymaga GET /api/products/export?ids=...&format=csv',
            })}
          >
            <Download className="size-4" />
            {t('products.bulk.export', { defaultValue: 'Eksport' })}
            <kbd className="ml-1.5 rounded bg-muted px-1 py-0.5 font-mono text-[10px] text-muted-foreground">
              X
            </kbd>
          </Button>
          <MockBadge
            tooltip={t('products.bulk.export_disabled', {
              defaultValue: 'Mock — wymaga GET /api/products/export?ids=...&format=csv',
            })}
          />
        </span>
        <Button
          type="button"
          variant="destructive"
          size="sm"
          onClick={handleDelete}
          disabled={isPending}
        >
          <Trash2 className="size-4" />
          {t('products.bulk.delete', { defaultValue: 'Delete' })}
        </Button>
      </div>

      <BulkChangeStatusDialog
        ids={ids}
        open={showStatusModal}
        onOpenChange={setShowStatusModal}
        onApplied={onCleared}
      />
      {showEditModal ? (
        <BulkEditModal
          productIds={ids}
          onClose={() => setShowEditModal(false)}
          onApplied={(job) => {
            setLastJob(job);
            if (job.errors_count === 0) onCleared();
          }}
        />
      ) : null}

      {lastJob !== null ? (
        <div className="rounded border bg-muted/40 px-2 py-1 text-xs">
          <span>
            {t('products.bulk.job_status', {
              status: lastJob.status,
              processed: lastJob.processed,
              total: lastJob.total,
              defaultValue: 'Job {{status}}: {{processed}}/{{total}}',
            })}
          </span>
          {lastJob.errors_count > 0 ? (
            <div className="mt-1 text-rose-600">
              {t('products.bulk.errors_count', {
                count: lastJob.errors_count,
                defaultValue: '{{count}} error(s)',
              })}
              <ul className="ml-4 mt-1 list-disc">
                {lastJob.first_errors.slice(0, 5).map((err) => (
                  <li key={err.objectId}>
                    <span className="font-mono">{err.objectId.slice(0, 8)}</span>: {err.message}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
