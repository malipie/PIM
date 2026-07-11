import { useCallback, useEffect, useMemo, useState } from 'react';

import { useListSchema } from '@/hooks/use-list-schema';

import { resolveGridColumns } from './resolve-grid-columns';
import type { GridColumn, GridColumnOverride, ViewColumnSeed } from './types';

/**
 * GRID-P1-01 (#2385) — single column model for both list views.
 *
 * Merges the ULV-03 list-schema with per-user quick-prefs persisted in
 * localStorage (`pim.objectList.{objectTypeId}.columns`, same key family
 * as viewMode/pageSize). The setters ship now so the column manager
 * (GRID-P2-01) only has to call them; SavedView round-trip lands in M4
 * with precedence SavedView > quick-prefs > schema default.
 */

function storageKey(objectTypeId: string): string {
  return `pim.objectList.${objectTypeId}.columns`;
}

/** `null` = nothing stored (defaults apply); `[]` = user explicitly reset to schema. */
function readStoredOverrides(objectTypeId: string): GridColumnOverride[] | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(storageKey(objectTypeId));
    if (raw === null) return null;
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;
    return parsed.filter(
      (entry): entry is GridColumnOverride =>
        entry !== null &&
        typeof entry === 'object' &&
        typeof (entry as GridColumnOverride).key === 'string',
    );
  } catch {
    // Corrupt JSON (manual edit, old shape) — fall back to defaults.
    return null;
  }
}

export interface UseGridColumnsOptions {
  /** View-owned derived columns (categories, sync, …) — see {@link ViewColumnSeed}. */
  viewColumns?: ViewColumnSeed[];
  /**
   * Applied only when the user has no stored prefs — lets a view keep
   * visual parity with its pre-GRID column set (e.g. products hide
   * `status`/`updatedAt` by default). "Reset" clears back to these.
   */
  defaultOverrides?: GridColumnOverride[];
}

export interface UseGridColumnsResult {
  /** Full resolved model (hidden columns included — the manager needs them). */
  columns: GridColumn[];
  /** Convenience: only the columns the views should render. */
  visibleColumns: GridColumn[];
  /** True while the schema query is loading or failed → fallback model. */
  isFallback: boolean;
  /** Replaces the override list (persists to localStorage). */
  setOverrides: (next: GridColumnOverride[]) => void;
  /** Clears quick-prefs → back to schema defaults. */
  resetOverrides: () => void;
}

export function useGridColumns(
  objectTypeId: string | undefined,
  options: UseGridColumnsOptions = {},
): UseGridColumnsResult {
  const { viewColumns, defaultOverrides } = options;
  const schemaQuery = useListSchema(objectTypeId);
  const [stored, setStoredState] = useState<GridColumnOverride[] | null>(() =>
    objectTypeId !== undefined ? readStoredOverrides(objectTypeId) : null,
  );

  // Re-read prefs when navigating between ObjectTypes — the hook instance
  // survives route param changes inside the same list component.
  useEffect(() => {
    setStoredState(objectTypeId !== undefined ? readStoredOverrides(objectTypeId) : null);
  }, [objectTypeId]);

  const overrides = stored ?? defaultOverrides ?? [];

  const setOverrides = useCallback(
    (next: GridColumnOverride[]) => {
      setStoredState(next);
      if (typeof window === 'undefined' || objectTypeId === undefined) return;
      try {
        window.localStorage.setItem(storageKey(objectTypeId), JSON.stringify(next));
      } catch {
        // Quota/private mode — prefs stay in-memory for the session.
      }
    },
    [objectTypeId],
  );

  const resetOverrides = useCallback(() => {
    setStoredState(null);
    if (typeof window === 'undefined' || objectTypeId === undefined) return;
    try {
      window.localStorage.removeItem(storageKey(objectTypeId));
    } catch {
      // Best effort — same rationale as setOverrides.
    }
  }, [objectTypeId]);

  const columns = useMemo(
    () => resolveGridColumns(schemaQuery.data, overrides, viewColumns),
    [schemaQuery.data, overrides, viewColumns],
  );
  const visibleColumns = useMemo(() => columns.filter((column) => !column.hidden), [columns]);

  return {
    columns,
    visibleColumns,
    isFallback: schemaQuery.data === undefined,
    setOverrides,
    resetOverrides,
  };
}
