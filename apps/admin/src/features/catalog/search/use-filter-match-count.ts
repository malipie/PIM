import { useMemo } from 'react';

import { conditionsToDsl, type FilterCondition, type FilterScope } from '@/lib/filters/filter-dsl';
import { dslToBase64 } from '@/lib/filters/url-serializer';

import { type CatalogSearchTarget, useCatalogSearch } from './use-catalog-search';

export interface UseFilterMatchCountState {
  count: number | undefined;
  isLoading: boolean;
}

/**
 * #2640 — live "how many objects match this filter" counter, shared by the
 * Web-To-Print scope step and the API Configurator outbound-filter section.
 *
 * Counts against the search index (`perPage: 1`, debounced in
 * {@link useCatalogSearch}) from a DRAFT condition set, so the number reacts
 * while the operator is still editing — before "Zastosuj filtr" commits the
 * DSL. Conditions whose value is still empty (a just-added row) are skipped
 * unless the operator is IS EMPTY / IS NOT EMPTY, so a fresh row does not
 * flash a misleading zero. An empty effective filter counts everything of the
 * target kind/type.
 */
export function useFilterMatchCount(
  target: CatalogSearchTarget,
  conditions: FilterCondition[],
  matchOperator: 'AND' | 'OR',
  scope?: FilterScope | null,
): UseFilterMatchCountState {
  const filterBlob = useMemo<string | undefined>(() => {
    const effective = conditions.filter(
      (cond) => cond.op === 'IS EMPTY' || cond.op === 'IS NOT EMPTY' || cond.value !== '',
    );
    // #2673 — the scope rides the blob; the search endpoint evaluates it
    // through the SQL prefilter, so the counter reflects the value context.
    const dsl = conditionsToDsl(effective, matchOperator, scope);
    if (dsl === null) return undefined;
    try {
      return dslToBase64(dsl);
    } catch {
      return undefined;
    }
  }, [conditions, matchOperator, scope]);

  const { result, isLoading } = useCatalogSearch({
    ...target,
    query: '',
    filterBlob,
    perPage: 1,
  });

  return { count: result?.totalHits, isLoading };
}
