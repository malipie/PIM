import type { FilterDsl } from '@/lib/filters/filter-dsl';

import type { ExportTargetScope } from './types';

export interface ExportScopeState {
  targetScope: ExportTargetScope;
  filterDsl: FilterDsl | null;
  selectedIds: string[] | null;
  includeVariants: boolean;
}

/**
 * #2987 — one wire contract for preflight and execution. The backend consumes
 * these exact keys in both endpoints, including the tree/flat variant choice.
 */
export function buildExportScopePayload(state: ExportScopeState): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    target_scope: state.targetScope,
    include_variants: state.includeVariants,
  };
  if (state.targetScope === 'filter' && state.filterDsl !== null) {
    payload.filter_snapshot = state.filterDsl;
  }
  if (state.targetScope === 'selected') {
    payload.selected_object_ids = state.selectedIds ?? [];
  }

  return payload;
}
