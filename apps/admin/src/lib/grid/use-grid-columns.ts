import { useCallback, useEffect, useMemo, useState } from 'react';

import { useListSchema } from '@/hooks/use-list-schema';

import { resolveGridColumns } from './resolve-grid-columns';
import type { GridColumn, GridColumnOverride } from './types';

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

function readOverrides(objectTypeId: string): GridColumnOverride[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = window.localStorage.getItem(storageKey(objectTypeId));
    if (raw === null) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter(
      (entry): entry is GridColumnOverride =>
        entry !== null &&
        typeof entry === 'object' &&
        typeof (entry as GridColumnOverride).key === 'string',
    );
  } catch {
    // Corrupt JSON (manual edit, old shape) — fall back to schema defaults.
    return [];
  }
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

export function useGridColumns(objectTypeId: string | undefined): UseGridColumnsResult {
  const schemaQuery = useListSchema(objectTypeId);
  const [overrides, setOverridesState] = useState<GridColumnOverride[]>(() =>
    objectTypeId !== undefined ? readOverrides(objectTypeId) : [],
  );

  // Re-read prefs when navigating between ObjectTypes — the hook instance
  // survives route param changes inside the same list component.
  useEffect(() => {
    setOverridesState(objectTypeId !== undefined ? readOverrides(objectTypeId) : []);
  }, [objectTypeId]);

  const setOverrides = useCallback(
    (next: GridColumnOverride[]) => {
      setOverridesState(next);
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
    setOverridesState([]);
    if (typeof window === 'undefined' || objectTypeId === undefined) return;
    try {
      window.localStorage.removeItem(storageKey(objectTypeId));
    } catch {
      // Best effort — same rationale as setOverrides.
    }
  }, [objectTypeId]);

  const columns = useMemo(
    () => resolveGridColumns(schemaQuery.data, overrides),
    [schemaQuery.data, overrides],
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
