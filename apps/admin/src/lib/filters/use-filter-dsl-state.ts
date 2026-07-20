import { useMemo, useState } from 'react';

import {
  conditionsToDsl,
  dslToFlatConditions,
  extractScope,
  type FilterCondition,
  type FilterDsl,
  type FilterScope,
  isFilterGroup,
} from './filter-dsl';

export interface FilterDslState {
  conditions: FilterCondition[];
  setConditions: (conditions: FilterCondition[]) => void;
  matchOperator: 'AND' | 'OR';
  setMatchOperator: (operator: 'AND' | 'OR') => void;
  /** #2673 — panel-wide value context (channel/locale), null = global. */
  scope: FilterScope | null;
  setScope: (scope: FilterScope | null) => void;
  /** Composed DSL (single condition stays unwrapped) — `null` when empty. */
  dsl: FilterDsl | null;
  clear: () => void;
}

/**
 * EXR-10 — shared state holder for `AdvancedFilterPanel` (Single Source
 * of Truth for filtering): the universal list page and the export
 * wizard hold the same conditions/operator pair and derive the composed
 * FilterDSL from one place. The panel itself stays fully props-driven.
 * #2673 added the value-context scope — seeded from the initial DSL root,
 * appended to the composed DSL, wiped by `clear()`.
 */
export function useFilterDslState(initial?: FilterDsl | null): FilterDslState {
  const [conditions, setConditions] = useState<FilterCondition[]>(
    () => dslToFlatConditions(initial ?? null) ?? [],
  );
  const [matchOperator, setMatchOperator] = useState<'AND' | 'OR'>(
    initial && isFilterGroup(initial) ? initial.operator : 'AND',
  );
  const [scope, setScope] = useState<FilterScope | null>(() => extractScope(initial ?? null));

  const dsl = useMemo(
    () => conditionsToDsl(conditions, matchOperator, scope),
    [conditions, matchOperator, scope],
  );

  return {
    conditions,
    setConditions,
    matchOperator,
    setMatchOperator,
    scope,
    setScope,
    dsl,
    clear: () => {
      setConditions([]);
      setScope(null);
    },
  };
}
