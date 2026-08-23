import { useCallback, useState } from 'react';

import { jsonFetch } from '@/lib/http';

/**
 * VIEW-11 (#542) — selection state shared across page + bulk + Cmd+K.
 *
 * Three modes mirror the toolbar's three states:
 *   - `none`     — nothing selected;
 *   - `page`     — explicit `Set<string>` of per-row toggles on the
 *                  visible page;
 *   - `all-matching` — server-resolved up to {@link HARD_CAP} IDs
 *                  matching the current filter; the toolbar warns when
 *                  `capped: true`.
 *
 * The hook owns the network call (`POST /api/products/select-all-matching`)
 * so callers don't have to reimplement payload shaping.
 */
export const HARD_CAP = 10_000;

export type SelectionMode = 'none' | 'page' | 'all-matching';

export interface SelectionState {
  mode: SelectionMode;
  ids: Set<string>;
  totalMatched: number;
  capped: boolean;
  isLoading: boolean;
  error: Error | null;
}

export interface SelectAllMatchingPayload {
  smartPreset?: string;
  filter?: string;
  q?: string;
  limit?: number;
  variantsMode?: 'tree' | 'flat';
  filters?: Record<string, string | string[]>;
  rangeFilters?: Record<string, { gte?: number; lte?: number }>;
}

interface SelectAllMatchingResponse {
  ids: string[];
  totalMatched: number;
  capped: boolean;
  limit: number;
}

export interface UseSelectionStateResult extends SelectionState {
  toggle: (id: string) => void;
  setPageSelection: (ids: Iterable<string>) => void;
  clear: () => void;
  selectAllMatching: (
    payload?: SelectAllMatchingPayload,
    visibleIds?: Iterable<string>,
  ) => Promise<void>;
}

export function useSelectionState(): UseSelectionStateResult {
  const [mode, setMode] = useState<SelectionMode>('none');
  const [ids, setIds] = useState<Set<string>>(new Set());
  const [totalMatched, setTotalMatched] = useState(0);
  const [capped, setCapped] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);

  const toggle = useCallback((id: string) => {
    setIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
    setMode((prev) => (prev === 'all-matching' ? 'page' : prev === 'none' ? 'page' : 'page'));
  }, []);

  const setPageSelection = useCallback((nextIds: Iterable<string>) => {
    const set = new Set(nextIds);
    setIds(set);
    setMode(set.size === 0 ? 'none' : 'page');
    setCapped(false);
  }, []);

  const clear = useCallback(() => {
    setIds(new Set());
    setMode('none');
    setCapped(false);
    setTotalMatched(0);
    setError(null);
  }, []);

  const selectAllMatching = useCallback(
    async (
      payload: SelectAllMatchingPayload = {},
      visibleIds: Iterable<string> = [],
    ): Promise<void> => {
      setIsLoading(true);
      setError(null);
      try {
        const response = await jsonFetch<SelectAllMatchingResponse>(
          '/api/products/select-all-matching',
          {
            method: 'POST',
            body: {
              smart_preset: payload.smartPreset,
              filter: payload.filter,
              q: payload.q,
              limit: payload.limit ?? HARD_CAP,
              variants_mode: payload.variantsMode ?? 'tree',
              filters: payload.filters,
              range_filters: payload.rangeFilters,
            },
          },
        );
        // The current page is authoritative visible state. Put its ids first
        // so an index refresh racing the selection request cannot leave the
        // checkboxes the operator can see unchecked after escalation.
        const resolved = new Set<string>();
        for (const id of visibleIds) {
          if (resolved.size >= response.limit) break;
          resolved.add(id);
        }
        for (const id of response.ids) {
          if (resolved.size >= response.limit) break;
          resolved.add(id);
        }
        setIds(resolved);
        setTotalMatched(response.totalMatched);
        setCapped(response.capped || resolved.size < response.totalMatched);
        setMode('all-matching');
      } catch (e) {
        const error = e instanceof Error ? e : new Error(String(e));
        setError(error);
        throw error;
      } finally {
        setIsLoading(false);
      }
    },
    [],
  );

  return {
    mode,
    ids,
    totalMatched,
    capped,
    isLoading,
    error,
    toggle,
    setPageSelection,
    clear,
    selectAllMatching,
  };
}
