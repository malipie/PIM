import type { FilterDsl } from './filter-dsl';
import { dslToFlatConditions } from './filter-dsl';
import { searchStringToDsl } from './url-serializer';

/**
 * DASH-01 (#2249) — deep-link seeding for the universal list page.
 *
 * Parses `filter[attr][op]=…&filter[attr][value]=…` (flat flavour) or
 * `?q=<base64>` (blob flavour) from the page URL into a FilterDsl that
 * pre-populates the advanced filter panel, so dashboard drill-downs like
 * `/products?filter[completeness_pct][op]=gte&filter[completeness_pct][value]=80`
 * land on an already-filtered list.
 *
 * `page` / `pageSize` are pagination params the list itself writes back
 * into the URL — they are stripped before parsing because the
 * serializer's shorthand fallback (`?brand=Festo`) would otherwise turn
 * them into bogus filter conditions (`pageSize` is not on the
 * serializer's skip list, which mirrors the BE contract and must not
 * drift).
 *
 * Nested DSL groups cannot seed the flat panel (`useFilterDslState`
 * flattens single-level only) — they resolve to `null` here so the list
 * falls back to plain browse instead of silently half-applying.
 */
export function readInitialFilterDsl(search: string): FilterDsl | null {
  const params = new URLSearchParams(search);
  params.delete('page');
  params.delete('pageSize');
  const dsl = searchStringToDsl(params.toString());
  if (dsl === null) return null;
  return dslToFlatConditions(dsl) === null ? null : dsl;
}
