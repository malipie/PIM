import type { GridColumn, GridColumnOverride } from './types';

/**
 * GRID-P2-01 (#2389) — serialises the manager's working state back into
 * the override list `useGridColumns` persists. Every column gets an
 * entry (array order == column order); `hidden`/`width`/`pinned` are
 * emitted only when set so the JSON stays minimal and the resolver's
 * defaults keep applying.
 */
export function overridesFromColumns(columns: GridColumn[]): GridColumnOverride[] {
  return columns.map((column) => ({
    key: column.key,
    ...(column.hidden ? { hidden: true } : {}),
    ...(column.width !== undefined ? { width: column.width } : {}),
    ...(column.pinned !== undefined ? { pinned: column.pinned } : {}),
  }));
}

/** Identifier column stays visible and first — the anchor of every row. */
export const LOCKED_COLUMN_KEY = 'code';

export function moveColumn(columns: GridColumn[], key: string, delta: -1 | 1): GridColumn[] {
  const index = columns.findIndex((column) => column.key === key);
  const target = index + delta;
  if (index <= 0 && delta === -1) return columns;
  if (index === -1 || target < 0 || target >= columns.length) return columns;
  // Never displace the locked identifier from position 0.
  if (columns[target]?.key === LOCKED_COLUMN_KEY || key === LOCKED_COLUMN_KEY) return columns;
  const next = [...columns];
  const [moved] = next.splice(index, 1);
  if (moved === undefined) return columns;
  next.splice(target, 0, moved);
  return next.map((column, position) => ({ ...column, position }));
}

export function toggleColumnHidden(columns: GridColumn[], key: string): GridColumn[] {
  if (key === LOCKED_COLUMN_KEY) return columns;
  return columns.map((column) =>
    column.key === key ? { ...column, hidden: !column.hidden } : column,
  );
}

export function setColumnWidth(
  columns: GridColumn[],
  key: string,
  width: number | undefined,
): GridColumn[] {
  return columns.map((column) => (column.key === key ? { ...column, width } : column));
}

/** Clamp for drag-resize — degenerate widths make cells unusable. */
export const MIN_COLUMN_WIDTH = 60;
export const MAX_COLUMN_WIDTH = 640;

export function clampColumnWidth(width: number): number {
  return Math.round(Math.min(MAX_COLUMN_WIDTH, Math.max(MIN_COLUMN_WIDTH, width)));
}

/**
 * GRID-P2-02 — double-click auto-fit heuristic: approximate content
 * width from the header label and a sample of cell strings (no canvas
 * measurement — ballpark is enough for a convenience gesture).
 */
export function autoFitWidth(label: string, samples: string[]): number {
  const longest = Math.max(label.length, ...samples.map((sample) => sample.length), 4);
  return clampColumnWidth(longest * 7.5 + 32);
}
