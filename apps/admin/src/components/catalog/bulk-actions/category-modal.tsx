import { FolderTree, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { buildCategoryTree, CategoryTree } from '@/components/modeling/category-tree';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/toast';
import { unwrapAttributesIndexed } from '@/lib/attributes-indexed';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * VIEW-14 (#546) — bulk category action modal.
 *
 * Three modes pick the same backend dispatch path (`add_category`,
 * `remove_category`, `move_category`). On Apply: success toast carries
 * a 5s Undo action that calls the 24h rollback endpoint immediately;
 * after the window expires the session remains rollbackable via the
 * sticky 24h toast (VIEW-17) and Audit panel.
 */

type Mode = 'add_category' | 'remove_category' | 'move_category';

interface BulkActionResult {
  session_id: string;
  action: string;
  target_count: number;
  success_count: number;
  skipped_count: number;
  error_count: number;
  rollback_available_until?: string;
  completed_at?: string;
}

interface CategoryRow {
  id: string;
  code: string;
  path?: string | null;
  attributesIndexed?: Record<string, unknown>;
}

function categoryName(row: CategoryRow): string {
  const name = unwrapAttributesIndexed(row.attributesIndexed).name;
  if (typeof name === 'string' && name.trim() !== '') return name;
  if (typeof name === 'object' && name !== null) {
    const map = name as Record<string, string>;
    return map.pl ?? map.en ?? Object.values(map)[0] ?? row.code;
  }
  return row.code;
}

interface CategoriesListResponse {
  'hydra:member'?: CategoryRow[];
  member?: CategoryRow[];
}

interface CategoryModalProps {
  selectedIds: string[];
  onClose: () => void;
  onApplied: (result: BulkActionResult) => void;
}

export function BulkCategoryModal({ selectedIds, onClose, onApplied }: CategoryModalProps) {
  const { t } = useTranslation();
  const [mode, setMode] = useState<Mode>('add_category');
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState<CategoryRow[]>([]);
  const [pickedIds, setPickedIds] = useState<Set<string>>(new Set());
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const load = async (): Promise<void> => {
      try {
        const response = await jsonFetch<CategoriesListResponse>(
          '/api/categories?itemsPerPage=200',
        );
        const rows = response['hydra:member'] ?? response.member ?? [];
        if (!cancelled) setOptions(rows);
      } catch {
        if (!cancelled) setOptions([]);
      }
    };
    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  const filteredOptions =
    search.trim() === ''
      ? options
      : options.filter((opt) => {
          const needle = search.trim().toLowerCase();
          return (
            categoryName(opt).toLowerCase().includes(needle) ||
            opt.code.toLowerCase().includes(needle) ||
            (opt.path ?? '').toLowerCase().includes(needle)
          );
        });

  // #2313 — render categories as the same name-labelled tree the operator
  // sees when editing categories, instead of a flat list of codes.
  const tree = useMemo(() => buildCategoryTree(filteredOptions), [filteredOptions]);

  const togglePick = (id: string): void => {
    setPickedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const apply = async (): Promise<void> => {
    if (pickedIds.size === 0) return;
    setIsLoading(true);
    try {
      const response = await jsonFetch<BulkActionResult>(`/api/products/bulk-actions/${mode}`, {
        method: 'POST',
        body: {
          target_ids: selectedIds,
          payload: { category_ids: Array.from(pickedIds) },
        },
      });
      toast.action({
        text: t('products.bulk_category.applied', {
          count: response.success_count,
          defaultValue: `Zaktualizowano ${response.success_count} produktów`,
        }),
        label: t('products.bulk_category.undo', { defaultValue: 'Cofnij' }),
        onClick: () => {
          void jsonFetch(`/api/bulk-sessions/${response.session_id}/rollback`, {
            method: 'POST',
          }).then(() => {
            toast.success(t('products.bulk_category.undone', { defaultValue: 'Cofnięto zmianę' }));
            onApplied(response);
          });
        },
      });
      onApplied(response);
      onClose();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'apply failed');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 bg-zinc-900/30 backdrop-blur-sm grid place-items-center">
      <button
        type="button"
        aria-label="Close backdrop"
        onClick={onClose}
        className="absolute inset-0 cursor-default"
      />
      <div
        className="relative bg-white rounded-3xl shadow-2xl w-[680px] max-w-[94vw] max-h-[80vh] overflow-hidden flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bulk-category-title"
      >
        <div className="px-6 h-14 flex items-center gap-3 border-b border-zinc-100">
          <span className="h-8 w-8 rounded-xl bg-zinc-900 text-white grid place-items-center">
            <FolderTree className="size-4" />
          </span>
          <div className="leading-tight">
            <div id="bulk-category-title" className="text-[14.5px] font-semibold tracking-tight">
              {t('products.bulk_category.title', { defaultValue: 'Akcja zbiorcza · Kategorie' })}
            </div>
            <div className="text-[11.5px] text-zinc-500 tabular-nums">
              {selectedIds.length}{' '}
              {t('products.bulk_wizard.target_count_label', {
                defaultValue: 'produktów wybranych',
              })}
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="ml-auto h-8 w-8 grid place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6 space-y-4">
          <div className="grid grid-cols-3 gap-2">
            {(['add_category', 'remove_category', 'move_category'] as const).map((m) => (
              <button
                key={m}
                type="button"
                onClick={() => setMode(m)}
                className={cn(
                  'h-9 px-3 rounded-lg text-[12px] font-medium border',
                  mode === m
                    ? 'bg-zinc-900 text-white border-zinc-900'
                    : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300',
                )}
              >
                {m === 'add_category'
                  ? t('products.bulk_category.mode_add', { defaultValue: 'Dodaj' })
                  : m === 'remove_category'
                    ? t('products.bulk_category.mode_remove', { defaultValue: 'Usuń' })
                    : t('products.bulk_category.mode_move', { defaultValue: 'Przenieś' })}
              </button>
            ))}
          </div>

          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('products.bulk_category.search_placeholder', {
              defaultValue: 'Szukaj kategorii…',
            })}
          />

          <div className="rounded-2xl border border-zinc-200 max-h-[260px] overflow-y-auto p-2">
            {tree.length === 0 ? (
              <div className="px-3 py-2 text-[12px] text-zinc-500">
                {t('products.bulk_category.empty', { defaultValue: 'Brak kategorii do pokazania' })}
              </div>
            ) : (
              <CategoryTree
                nodes={tree}
                mode="multi-select"
                selectedIds={pickedIds}
                onToggle={togglePick}
                onSelect={togglePick}
                hidePrimary
              />
            )}
          </div>

          <div className="text-[11.5px] text-zinc-500">
            {pickedIds.size}{' '}
            {t('products.bulk_category.picked_label', {
              defaultValue: 'kategorii wybranych',
            })}
          </div>
        </div>

        <div className="px-6 h-14 flex items-center gap-3 border-t border-zinc-100 bg-zinc-50/50">
          <span className="text-[11.5px] text-zinc-500">
            {t('products.bulk_wizard.rollback_hint', {
              defaultValue: 'Każda akcja zbiorcza ma 24h soft-rollback.',
            })}
          </span>
          <div className="ml-auto flex items-center gap-2">
            <Button variant="ghost" onClick={onClose} disabled={isLoading}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button onClick={() => void apply()} disabled={isLoading || pickedIds.size === 0}>
              {t('products.bulk_wizard.apply', { defaultValue: 'Zastosuj' })}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
