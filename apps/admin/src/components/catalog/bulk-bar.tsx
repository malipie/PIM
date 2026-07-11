import {
  Copy,
  Download,
  FolderTree,
  GitBranch,
  MoreHorizontal,
  Pencil,
  Sparkles,
  Trash2,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { PermissionGate } from '@/components/identity';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from '@/components/ui/toast';
import { cn } from '@/lib/utils';

interface BulkBarProps {
  selectedIds: string[];
  onClear: () => void;
  onApplied: () => void;
  onOpenWizard?: () => void;
  onOpenCategoryModal?: () => void;
  onOpenDeleteModal?: () => void;
  onOpenDuplicateModal?: () => void;
  onOpenCmdK?: () => void;
  /**
   * EXR-14 — navigates to the export wizard with target_scope=selected
   * + selected_object_ids = selectedIds. Backend `POST /api/products/export`
   * sync <100 SKU → BinaryFileResponse, ≥100 → async session.
   */
  onOpenExportModal?: () => void;
  /**
   * AICG-P5-03 (#2341) — opens the bulk "Generuj treść AI" modal
   * (descriptions / SEO via the content tools, one pending batch in the
   * agent inbox). Supplied only on product-kind lists.
   */
  onOpenGenerateContent?: () => void;
  /**
   * WFL-P3-04 / #2493 — opens the bulk workflow-transition dialog
   * (`POST /api/products/bulk-actions/change_status`, transitions
   * evaluated per row by the state machine). Supplied only on
   * product-kind lists — the endpoint is products-only.
   */
  onOpenChangeStatus?: () => void;
}

/**
 * VIEW-05 (#411) — pixel-perfect sticky bottom bar matching the
 * prototype mockup `produkty/list-view.jsx` lines 176–198. Four mockup
 * actions (edit attribute, change category, export, delegate to agent)
 * surface as toast placeholders pointing at follow-ups VIEW-05.2–5; the
 * overflow `…` menu keeps the existing toggle_enabled bulk operation
 * wired through `POST /api/products/bulk-edit` so the only currently
 * shipped destructive action stays accessible.
 */
export function BulkBar({
  selectedIds,
  onClear,
  onOpenWizard,
  onOpenCategoryModal,
  onOpenDeleteModal,
  onOpenDuplicateModal,
  onOpenCmdK,
  onOpenExportModal,
  onOpenGenerateContent,
  onOpenChangeStatus,
}: BulkBarProps) {
  const { t } = useTranslation();

  if (selectedIds.length === 0) return null;

  const placeholder = (followUp: string) => () => {
    toast.info(
      t('products.bulk.placeholder_in_progress', {
        followUp,
        defaultValue: 'W przygotowaniu — {{followUp}}',
      }),
    );
  };

  // RBAC-P6-005 (#717) — bulk action surface is gated behind
  // `products.bulk_operations`. Roles without that permission still see
  // checkboxes on the underlying list (Phase 4 visibility) but the
  // mutation bar never renders. Backend 403 toast is the fallback.
  return (
    <PermissionGate code="products.bulk_operations">
      <div className="fixed bottom-6 left-0 right-0 z-40 flex justify-center pointer-events-none">
        <section
          aria-label={t('products.bulk.aria_region', { defaultValue: 'Akcje masowe' })}
          className="pointer-events-auto bg-zinc-900 text-white rounded-3xl shadow-xl px-6 py-3 flex items-center gap-5 animate-in fade-in slide-in-from-bottom-2 duration-200"
          data-testid="bulk-bar"
        >
          <div className="flex items-center gap-2">
            <span className="h-7 w-7 rounded-xl bg-white/10 grid place-items-center text-[12px] font-semibold tabular-nums">
              {selectedIds.length}
            </span>
            <span className="text-[13px] font-medium">
              {t('products.bulk.selected_label', { defaultValue: 'zaznaczonych produktów' })}
            </span>
          </div>

          <span className="h-6 w-px bg-white/15" aria-hidden="true" />

          <button
            type="button"
            onClick={onOpenWizard ?? placeholder('VIEW-05.2')}
            className="text-[13px] font-medium text-white/90 hover:text-white inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 rounded"
          >
            <Pencil className="size-3.5" aria-hidden="true" />
            {t('products.bulk.edit_attribute', { defaultValue: 'Edytuj atrybut' })}
          </button>
          <button
            type="button"
            onClick={onOpenCategoryModal ?? placeholder('VIEW-05.3')}
            className="text-[13px] font-medium text-white/90 hover:text-white inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 rounded"
          >
            <FolderTree className="size-3.5" aria-hidden="true" />
            {t('products.bulk.change_category', { defaultValue: 'Zmień kategorię' })}
          </button>
          {onOpenChangeStatus !== undefined ? (
            <button
              type="button"
              onClick={onOpenChangeStatus}
              data-testid="bulk-change-status"
              className="text-[13px] font-medium text-white/90 hover:text-white inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 rounded"
            >
              <GitBranch className="size-3.5" aria-hidden="true" />
              {t('products.bulk.change_status', { defaultValue: 'Zmień status' })}
            </button>
          ) : null}
          <button
            type="button"
            onClick={onOpenExportModal ?? placeholder('VIEW-05.4')}
            className="text-[13px] font-medium text-white/90 hover:text-white inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 rounded"
          >
            <Download className="size-3.5" aria-hidden="true" />
            {t('products.bulk.export', { defaultValue: 'Eksport' })}
          </button>
          {onOpenGenerateContent !== undefined ? (
            <button
              type="button"
              onClick={onOpenGenerateContent}
              data-testid="bulk-generate-content"
              className={cn(
                'text-[13px] font-medium px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-500 inline-flex items-center gap-1.5',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60',
              )}
            >
              <Sparkles className="size-3.5" aria-hidden="true" />
              {t('aicg.bulk.action', { defaultValue: 'Generuj treść AI' })}
            </button>
          ) : null}
          <button
            type="button"
            onClick={onOpenCmdK ?? placeholder('VIEW-05.5')}
            className={cn(
              'text-[13px] font-medium px-3 py-1.5 rounded-xl bg-orange-500 hover:bg-orange-400 inline-flex items-center gap-1.5',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60',
            )}
          >
            <Sparkles className="size-3.5" aria-hidden="true" />
            {t('products.bulk.delegate_agent', { defaultValue: 'Zleć agentowi (⌘K)' })}
          </button>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                aria-label={t('products.bulk.more_actions', { defaultValue: 'Więcej akcji' })}
                className="text-white/70 hover:text-white inline-flex items-center justify-center size-7 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 disabled:opacity-50"
              >
                <MoreHorizontal className="size-4" aria-hidden="true" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {onOpenDuplicateModal ? (
                <DropdownMenuItem
                  onSelect={() => {
                    onOpenDuplicateModal();
                  }}
                >
                  <Copy className="mr-2 size-3.5" aria-hidden="true" />
                  {t('products.bulk.duplicate', { defaultValue: 'Duplikuj' })}
                </DropdownMenuItem>
              ) : null}
              {onOpenDeleteModal ? (
                <DropdownMenuItem
                  onSelect={() => {
                    onOpenDeleteModal();
                  }}
                  className="text-rose-700 focus:bg-rose-50 focus:text-rose-700"
                >
                  <Trash2 className="mr-2 size-3.5" aria-hidden="true" />
                  {t('products.bulk.delete', { defaultValue: 'Usuń' })}
                </DropdownMenuItem>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>

          <span className="h-6 w-px bg-white/15" aria-hidden="true" />

          <button
            type="button"
            onClick={onClear}
            className="text-[13px] text-white/60 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 rounded"
          >
            {t('products.bulk.clear', { defaultValue: 'Wyczyść' })}
          </button>
        </section>
      </div>
    </PermissionGate>
  );
}
