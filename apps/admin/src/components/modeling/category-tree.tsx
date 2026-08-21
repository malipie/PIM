import { ChevronRight, FolderTree, Star } from 'lucide-react';
import { useState } from 'react';

import { unwrapAttributesIndexed } from '@/lib/attributes-indexed';
import { cn } from '@/lib/utils';

export interface CategoryTreeNode {
  id: string;
  code: string;
  label: string;
  path: string;
  depth: number;
  icon?: string | null;
  groupColors?: string[];
  instanceCount?: number;
  children: CategoryTreeNode[];
}

type CategoryTreeMode = 'select' | 'multi-select';

interface Props {
  nodes: CategoryTreeNode[];
  selectedId?: string;
  onSelect: (id: string) => void;
  /** IDs disabled (e.g. when reusing the tree inside a Move dialog to forbid the moving subtree). */
  disabledIds?: Set<string>;
  /** Pre-expanded node ids — derived from URL search param in the list page. */
  initialExpanded?: Set<string>;
  /**
   * PCAT-05 (#478) — switches the tree to multi-select mode for the
   * category picker on the product form. In `'multi-select'`:
   *   - `selectedIds` controls the checkbox state (toggle via `onToggle`)
   *   - `primaryId` highlights one row with a ⭐ radio (toggle via
   *     `onPrimaryChange`); only enabled when the row is selected
   *   - `onSelect` is still called on row click but consumers typically
   *     wire it to `onToggle`
   * Default `'select'` keeps the modeling/Move usage unchanged.
   */
  mode?: CategoryTreeMode;
  selectedIds?: Set<string>;
  onToggle?: (id: string) => void;
  primaryId?: string | null;
  onPrimaryChange?: (id: string) => void;
  /**
   * #2313 — hide the ⭐ primary-category column in multi-select. The bulk
   * category action (add/remove/move) has no "primary" concept, so the
   * picker is checkbox-only.
   */
  hidePrimary?: boolean;
}

/**
 * VIEW-04 (#408) — recursive ltree renderer for the modeling Category
 * tree (left panel of `/modeling/categories`). Reused inside
 * {@link MoveCategoryDialog} for the parent-picker, hence the optional
 * `disabledIds` prop to forbid moving a node into its own subtree.
 *
 * Matches the prototype `groups-categories.jsx:425–455` row layout:
 *   - `rounded-xl bg-zinc-900 text-white` for the selected node.
 *   - 4×4 chevron button toggling expand/collapse (rotated 90deg when expanded).
 *   - Optional emoji glyph + bold name + tabular instance count.
 *   - Up to 3 group color dots as a per-row indicator + "+N" overflow.
 */
export function CategoryTree({
  nodes,
  selectedId,
  onSelect,
  disabledIds,
  initialExpanded,
  mode = 'select',
  selectedIds,
  onToggle: onToggleSelection,
  primaryId,
  onPrimaryChange,
  hidePrimary = false,
}: Props) {
  const [expanded, setExpanded] = useState<Set<string>>(() => initialExpanded ?? new Set());

  const toggleExpand = (id: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  return (
    <ul className="space-y-1">
      {nodes.map((node) => (
        <CategoryTreeRow
          key={node.id}
          node={node}
          selectedId={selectedId}
          onSelect={onSelect}
          disabledIds={disabledIds}
          expanded={expanded}
          onToggleExpand={toggleExpand}
          mode={mode}
          selectedIds={selectedIds}
          onToggleSelection={onToggleSelection}
          primaryId={primaryId ?? null}
          onPrimaryChange={onPrimaryChange}
          hidePrimary={hidePrimary}
        />
      ))}
    </ul>
  );
}

function CategoryTreeRow({
  node,
  selectedId,
  onSelect,
  disabledIds,
  expanded,
  onToggleExpand,
  mode,
  selectedIds,
  onToggleSelection,
  primaryId,
  onPrimaryChange,
  hidePrimary,
}: {
  node: CategoryTreeNode;
  selectedId?: string;
  onSelect: (id: string) => void;
  disabledIds?: Set<string>;
  expanded: Set<string>;
  onToggleExpand: (id: string) => void;
  mode: CategoryTreeMode;
  selectedIds?: Set<string>;
  onToggleSelection?: (id: string) => void;
  primaryId: string | null;
  onPrimaryChange?: (id: string) => void;
  hidePrimary: boolean;
}) {
  const hasChildren = node.children.length > 0;
  const isExpanded = expanded.has(node.id);
  const isMulti = mode === 'multi-select';
  const isChecked = isMulti ? (selectedIds?.has(node.id) ?? false) : false;
  const isPrimary = isMulti ? primaryId === node.id : false;
  const isSelected = isMulti ? isChecked : selectedId === node.id;
  const isDisabled = disabledIds?.has(node.id) ?? false;
  const groupColors = node.groupColors ?? [];

  const handleRowClick = () => {
    if (isDisabled) return;
    if (isMulti) {
      onToggleSelection?.(node.id);
      return;
    }
    onSelect(node.id);
  };

  const rowVisualClass = isMulti
    ? isChecked
      ? 'bg-orange-50/60 hover:bg-orange-50'
      : 'hover:bg-zinc-100/70'
    : isSelected
      ? 'bg-zinc-900 text-white'
      : 'hover:bg-zinc-100/70';

  return (
    <li>
      <div
        className={cn(
          'flex w-full items-center gap-1.5 rounded-xl px-2 py-1.5 text-left transition-colors',
          rowVisualClass,
          isDisabled && 'cursor-not-allowed opacity-50',
        )}
        style={{ paddingLeft: `${8 + node.depth * 16}px` }}
      >
        {isMulti ? (
          <input
            type="checkbox"
            checked={isChecked}
            disabled={isDisabled}
            onChange={() => onToggleSelection?.(node.id)}
            className="size-3.5 cursor-pointer accent-orange-600"
            aria-label={`Wybierz kategorię ${node.label}`}
            onClick={(e) => e.stopPropagation()}
          />
        ) : null}
        {hasChildren ? (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onToggleExpand(node.id);
            }}
            className="grid size-5 place-items-center"
            aria-label={isExpanded ? 'Zwiń' : 'Rozwiń'}
          >
            <ChevronRight
              className={cn(
                'size-3.5 transition-transform',
                isSelected && !isMulti ? 'text-white/70' : 'text-zinc-500',
              )}
              style={{ transform: isExpanded ? 'rotate(90deg)' : 'rotate(0deg)' }}
              aria-hidden
            />
          </button>
        ) : (
          <span className="grid size-5 place-items-center text-zinc-300">
            <FolderTree className="size-3" />
          </span>
        )}
        <button
          type="button"
          disabled={isDisabled}
          onClick={handleRowClick}
          className="flex flex-1 items-center gap-1.5 text-left"
        >
          <span className="text-[14px]">{node.icon ?? '📁'}</span>
          <span className="text-[13px] font-medium">{node.label}</span>
          {node.instanceCount !== undefined ? (
            <span
              className={cn(
                'ml-1 font-mono text-[10.5px] tabular-nums',
                isSelected && !isMulti ? 'text-white/50' : 'text-zinc-500',
              )}
            >
              {node.instanceCount}
            </span>
          ) : null}
          {groupColors.length > 0 && !isMulti ? (
            <span className="ml-auto inline-flex items-center gap-0.5">
              {groupColors.slice(0, 3).map((color) => (
                <span
                  key={color}
                  className="size-2 rounded-full"
                  style={{ background: color }}
                  aria-hidden
                />
              ))}
              {groupColors.length > 3 ? (
                <span
                  className={cn(
                    'ml-0.5 text-[10px]',
                    isSelected ? 'text-white/50' : 'text-zinc-500',
                  )}
                >
                  +{groupColors.length - 3}
                </span>
              ) : null}
            </span>
          ) : null}
        </button>
        {isMulti && !hidePrimary ? (
          <button
            type="button"
            disabled={!isChecked}
            onClick={(e) => {
              e.stopPropagation();
              if (isChecked) onPrimaryChange?.(node.id);
            }}
            className={cn(
              'ml-auto grid size-6 place-items-center rounded-lg transition-colors',
              isPrimary
                ? 'bg-amber-100 text-amber-700'
                : isChecked
                  ? 'text-zinc-500 hover:bg-amber-50 hover:text-amber-600'
                  : 'cursor-not-allowed text-zinc-200',
            )}
            aria-label={isPrimary ? 'Kategoria główna' : 'Ustaw jako główną'}
            aria-pressed={isPrimary}
            title={
              !isChecked
                ? 'Najpierw zaznacz kategorię'
                : isPrimary
                  ? 'Kategoria główna'
                  : 'Ustaw jako główną'
            }
          >
            <Star className={cn('size-3.5', isPrimary ? 'fill-amber-500' : '')} />
          </button>
        ) : null}
      </div>
      {hasChildren && isExpanded ? (
        <ul className="space-y-1">
          {node.children.map((child) => (
            <CategoryTreeRow
              key={child.id}
              node={child}
              selectedId={selectedId}
              onSelect={onSelect}
              disabledIds={disabledIds}
              expanded={expanded}
              onToggleExpand={onToggleExpand}
              mode={mode}
              selectedIds={selectedIds}
              onToggleSelection={onToggleSelection}
              primaryId={primaryId}
              onPrimaryChange={onPrimaryChange}
              hidePrimary={hidePrimary}
            />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

/**
 * Convert a flat list of CatalogObject (kind=category) rows into the
 * recursive shape the renderer expects. Matches the algorithm in
 * `apps/admin/src/features/catalog/categories/list.tsx` pre-rebuild —
 * extracted as a util so other consumers (e.g. MoveCategoryDialog) can
 * reuse it.
 */
export function buildCategoryTree(
  rows: Array<{
    id: string;
    code: string;
    path?: string | null;
    icon?: string | null;
    instanceCount?: number;
    groupColors?: string[];
    attributesIndexed?: Record<string, unknown>;
  }>,
): CategoryTreeNode[] {
  const nodes: CategoryTreeNode[] = rows.map((row) => {
    const path = row.path ?? row.code;
    const segments = path.split('.').filter((s) => s.length > 0);
    const label = categoryLabelFromAttributes(row.attributesIndexed) ?? row.code;
    return {
      id: row.id,
      code: row.code,
      label,
      path,
      depth: Math.max(0, segments.length - 1),
      icon: row.icon,
      groupColors: row.groupColors,
      instanceCount: row.instanceCount,
      children: [],
    };
  });

  nodes.sort((a, b) => a.path.localeCompare(b.path));

  const roots: CategoryTreeNode[] = [];
  for (const node of nodes) {
    if (node.depth === 0) {
      roots.push(node);
      continue;
    }
    const parentPath = node.path.split('.').slice(0, -1).join('.');
    const parent = nodes.find((c) => c.path === parentPath);
    if (parent) {
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }

  return roots;
}

export function categoryLabelFromAttributes(
  attrs: Record<string, unknown> | null | undefined,
): string | null {
  const name = unwrapAttributesIndexed(attrs).name;
  if (typeof name === 'string') return name;
  if (typeof name === 'object' && name !== null) {
    const map = name as Record<string, string>;
    return map.pl ?? map.en ?? Object.values(map)[0] ?? null;
  }
  return null;
}
