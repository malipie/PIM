import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, FolderTree } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { CategoryTree, type CategoryTreeNode } from '@/components/modeling/category-tree';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { useToast } from '@/components/ui/toast';
import { HttpError, httpErrorDetail, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

interface MoveImpact {
  affectedObjectsCount: number;
  schemaWillChange: boolean;
  addedGroupLabels: string[];
  removedGroupLabels: string[];
}

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The category being re-parented. */
  category: CategoryTreeNode;
  /** Full tree of the current target-ObjectType — the parent picker. */
  tree: CategoryTreeNode[];
  /** Called after a successful move so the caller can refetch the tree. */
  onMoved: () => void;
}

/**
 * DP-01 (#2031) — re-parent a category subtree via
 * `PATCH /api/categories/{id}/move` (VIEW-04 #408 backend).
 *
 * The parent picker reuses {@link CategoryTree} with `disabledIds` set to
 * the moving node + all its descendants (cycle guard mirrors the ltree
 * `<@` check server-side). "Move to root" is an explicit row above the
 * tree (`newParentId: null`). Before committing, the CHC-05 (#1287)
 * move-impact endpoint previews the blast radius; when products are
 * affected the backend answers 409 until the operator confirms, so the
 * dialog surfaces the added/removed groups and retries with
 * `?confirmed=true` after an explicit second click.
 */
/**
 * State lives one level below the `open` gate: the caller mounts the
 * dialog only while open (see list.tsx), so every opening starts from a
 * fresh picker — no reset effect needed (ADR-0021 guard also counts
 * jsonFetch+useEffect co-occurrence per file).
 */
export function MoveCategoryDialog({ open, onOpenChange, category, tree, onMoved }: Props) {
  const { t } = useTranslation();
  const toast = useToast();
  // `undefined` = nothing picked yet; `null` = root.
  const [targetParentId, setTargetParentId] = useState<string | null | undefined>(undefined);
  const [submitting, setSubmitting] = useState(false);
  const [needsConfirm, setNeedsConfirm] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const disabledIds = useMemo(() => {
    const ids = new Set<string>();
    const walk = (node: CategoryTreeNode) => {
      ids.add(node.id);
      node.children.forEach(walk);
    };
    const self = findNode(tree, category.id);
    if (self) walk(self);
    else ids.add(category.id);
    // The current parent is a pointless target — moving there is a no-op.
    const parentPath = category.path.split('.').slice(0, -1).join('.');
    const parent = parentPath === '' ? null : findByPath(tree, parentPath);
    if (parent) ids.add(parent.id);
    return ids;
  }, [tree, category]);

  const isRootAlready = !category.path.includes('.');
  const hasTarget = targetParentId !== undefined;

  const { data: impact } = useQuery<MoveImpact>({
    queryKey: ['categories', category.id, 'move-impact', targetParentId ?? 'root'],
    queryFn: () =>
      jsonFetch<MoveImpact>(
        `/api/categories/${category.id}/move-impact${
          targetParentId ? `?targetParentId=${targetParentId}` : ''
        }`,
        { accept: 'application/json' },
      ),
    enabled: open && hasTarget,
    staleTime: 5_000,
  });

  const submit = async () => {
    if (!hasTarget || submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      const confirmedSuffix = needsConfirm ? '?confirmed=true' : '';
      await jsonFetch<{ categoryId: string; newPath: string; affectedDescendants: number }>(
        `/api/categories/${category.id}/move${confirmedSuffix}`,
        { method: 'PATCH', body: { newParentId: targetParentId } },
      );
      toast.success(
        t('categories.move_dialog.success', {
          defaultValue: 'Kategoria „{{label}}" przeniesiona.',
          label: category.label,
        }),
      );
      onOpenChange(false);
      onMoved();
    } catch (err) {
      if (err instanceof HttpError && err.status === 409) {
        // Impact gate — show the blast radius and ask for the second click.
        setNeedsConfirm(true);
      } else {
        setError(
          httpErrorDetail(err) ??
            t('categories.move_dialog.error', { defaultValue: 'Przeniesienie nie powiodło się.' }),
        );
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[560px] gap-0 p-0">
        <div className="border-b border-zinc-100 px-7 pb-4 pt-6">
          <div className="flex items-center gap-2">
            <FolderTree className="size-4 text-zinc-500" />
            <h2 className="text-[15px] font-semibold tracking-tight">
              {t('categories.move_dialog.title', {
                defaultValue: 'Przenieś kategorię „{{label}}"',
                label: category.label,
              })}
            </h2>
          </div>
          <p className="mt-1 text-[12.5px] text-zinc-500">
            {t('categories.move_dialog.description', {
              defaultValue:
                'Wybierz nowego rodzica. Całe poddrzewo przenosi się razem z kategorią; nie można przenieść kategorii do niej samej ani do jej potomka.',
            })}
          </p>
        </div>

        <div className="max-h-[340px] space-y-1 overflow-auto px-7 py-4">
          <button
            type="button"
            disabled={isRootAlready}
            onClick={() => setTargetParentId(null)}
            className={cn(
              'flex w-full items-center gap-1.5 rounded-xl px-2 py-1.5 text-left text-[13px] font-medium transition-colors',
              targetParentId === null ? 'bg-zinc-900 text-white' : 'hover:bg-zinc-100/70',
              isRootAlready && 'cursor-not-allowed opacity-50',
            )}
          >
            <FolderTree className="size-3.5" />
            {t('categories.move_dialog.to_root', { defaultValue: 'Poziom główny (root)' })}
          </button>
          <CategoryTree
            nodes={tree}
            selectedId={targetParentId ?? undefined}
            onSelect={(id) => setTargetParentId(id)}
            disabledIds={disabledIds}
          />
        </div>

        {hasTarget && impact && (impact.affectedObjectsCount > 0 || impact.schemaWillChange) ? (
          <div className="mx-7 mb-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[12.5px] text-amber-900">
            <div className="flex items-center gap-2 font-medium">
              <AlertTriangle className="size-3.5" />
              {t('categories.move_dialog.impact_title', {
                defaultValue: 'Wpływ na produkty: {{count}}',
                count: impact.affectedObjectsCount,
              })}
            </div>
            {impact.addedGroupLabels.length > 0 ? (
              <div className="mt-1">
                {t('categories.move_dialog.impact_added', { defaultValue: 'Dojdą grupy:' })}{' '}
                {impact.addedGroupLabels.join(', ')}
              </div>
            ) : null}
            {impact.removedGroupLabels.length > 0 ? (
              <div className="mt-1">
                {t('categories.move_dialog.impact_removed', { defaultValue: 'Znikną grupy:' })}{' '}
                {impact.removedGroupLabels.join(', ')}
              </div>
            ) : null}
          </div>
        ) : null}

        {error ? (
          <div className="mx-7 mb-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[12.5px] text-red-800">
            {error}
          </div>
        ) : null}

        <div className="flex items-center justify-end gap-2 border-t border-zinc-100 px-7 py-4">
          <Button variant="ghost" size="sm" onClick={() => onOpenChange(false)}>
            {t('app.cancel', { defaultValue: 'Anuluj' })}
          </Button>
          <Button
            size="sm"
            disabled={!hasTarget || submitting}
            onClick={() => void submit()}
            className={cn(
              'rounded-xl',
              needsConfirm ? 'bg-amber-600 hover:bg-amber-700' : 'bg-zinc-900 hover:bg-zinc-800',
            )}
          >
            {needsConfirm
              ? t('categories.move_dialog.confirm_cta', {
                  defaultValue: 'Potwierdź mimo wpływu na produkty',
                })
              : t('categories.move_dialog.cta', { defaultValue: 'Przenieś' })}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function findNode(nodes: CategoryTreeNode[], id: string): CategoryTreeNode | null {
  for (const node of nodes) {
    if (node.id === id) return node;
    const hit = findNode(node.children, id);
    if (hit) return hit;
  }
  return null;
}

function findByPath(nodes: CategoryTreeNode[], path: string): CategoryTreeNode | null {
  for (const node of nodes) {
    if (node.path === path) return node;
    const hit = findByPath(node.children, path);
    if (hit) return hit;
  }
  return null;
}
