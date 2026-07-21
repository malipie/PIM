/**
 * VIEW-09 (#535) — Filter DSL shared between Smart Filter Presets,
 * Advanced Filter Panel, and URL serializer.
 *
 * Flat (single-level) form:
 *   { attr: 'brand', op: 'IN', value: ['Festo', 'Bosch'] }
 *
 * Grouped form (one level of grouping in VIEW-09; nested AND/OR/NOT
 * lands in VIEW-09b without schema changes):
 *   { operator: 'AND', conditions: [
 *     { attr: 'description.pl', op: 'IS NOT EMPTY' },
 *     { attr: 'description.en', op: 'IS EMPTY' },
 *   ]}
 *
 * Backend resolver mirrors this exactly — see FilterDslResolver.php.
 * URL serializer compresses single-level conditions into shareable
 * params (see `url-serializer.ts`); query mode falls back to a
 * base64 blob landing in VIEW-09b.
 */

export type FilterOperator =
  | '='
  | '!='
  | '≠'
  | 'IS EMPTY'
  | 'IS NOT EMPTY'
  | '<'
  | '>'
  | '<='
  | '>='
  | 'IN'
  | 'NOT IN'
  | 'starts with'
  | 'ends with'
  | 'contains'
  | 'not contains'
  | 'between'
  | 'after'
  | 'before'
  | '= TRUE'
  | '= FALSE';

export type FilterConditionValue = string | number | boolean | Array<string | number> | null;

/**
 * #2673 — value context of a filter document. When set, every condition
 * evaluates against the value for that channel/locale (with fallback to the
 * global slot) on the backend. Codes, not UUIDs. Root-level only by
 * convention — the panel-wide selector applies to the whole document.
 */
export interface FilterScope {
  channel?: string;
  locale?: string;
}

export interface FilterCondition {
  attr: string;
  op: FilterOperator;
  value?: FilterConditionValue;
  /** #2673 — only meaningful on the DSL ROOT (single-condition documents). */
  scope?: FilterScope;
}

export interface FilterGroup {
  operator: 'AND' | 'OR';
  conditions: Array<FilterCondition | FilterGroup>;
  /** #2673 — only meaningful on the DSL ROOT. */
  scope?: FilterScope;
}

export type FilterDsl = FilterCondition | FilterGroup;

/**
 * Pełna lista operatorów per typ atrybutu w VIEW-10. VIEW-09 hardcoded
 * obsługuje 6 wspólnych operatorów ({@link CORE_OPERATORS}).
 */
export const CORE_OPERATORS: readonly FilterOperator[] = [
  '=',
  '≠',
  'IN',
  'NOT IN',
  'IS EMPTY',
  'IS NOT EMPTY',
] as const;

/**
 * VIEW-10 (#538) — 25 operators per type mirror with BE
 * (`FilterDslResolver::OPERATORS_BY_TYPE`). Keep in sync via the
 * `lib/filters/operators.ts` typed mirror.
 */
export const FILTER_OPERATORS_BY_TYPE: Record<string, readonly FilterOperator[]> = {
  text: [
    '=',
    '!=',
    'IS EMPTY',
    'IS NOT EMPTY',
    'starts with',
    'ends with',
    'contains',
    'not contains',
  ],
  wysiwyg: [
    '=',
    '!=',
    'IS EMPTY',
    'IS NOT EMPTY',
    'starts with',
    'ends with',
    'contains',
    'not contains',
  ],
  number: ['=', '!=', '<', '>', '<=', '>=', 'between', 'IS EMPTY', 'IS NOT EMPTY'],
  metric: ['=', '!=', '<', '>', '<=', '>=', 'between', 'IS EMPTY', 'IS NOT EMPTY'],
  price: ['=', '!=', '<', '>', '<=', '>=', 'between', 'IS EMPTY', 'IS NOT EMPTY'],
  date: ['=', '!=', 'after', 'before', 'between', 'IS EMPTY', 'IS NOT EMPTY'],
  datetime: ['=', '!=', 'after', 'before', 'between', 'IS EMPTY', 'IS NOT EMPTY'],
  select: ['=', '!=', 'IN', 'NOT IN', 'IS EMPTY', 'IS NOT EMPTY'],
  multiselect: ['contains', 'not contains', 'IS EMPTY', 'IS NOT EMPTY'],
  boolean: ['= TRUE', '= FALSE'],
  relation: ['=', '!=', 'IN', 'NOT IN', 'IS EMPTY', 'IS NOT EMPTY'],
  reference: ['IS EMPTY', 'IS NOT EMPTY'],
  asset: ['IS EMPTY', 'IS NOT EMPTY'],
};

/**
 * VIEW-10 — operator label helpers for UI.
 */
export function operatorRequiresValue(op: FilterOperator): boolean {
  return op !== 'IS EMPTY' && op !== 'IS NOT EMPTY' && op !== '= TRUE' && op !== '= FALSE';
}

export function operatorRequiresArray(op: FilterOperator): boolean {
  return op === 'IN' || op === 'NOT IN';
}

export function operatorRequiresRange(op: FilterOperator): boolean {
  return op === 'between';
}

/**
 * VIEW-10 — alias resolver (UI label → canonical), mirrors
 * `FilterDslResolver::normaliseOperator()` from BE.
 */
export function normaliseOperator(op: string): FilterOperator {
  const trimmed = op.trim();
  const aliases: Record<string, FilterOperator> = {
    '≠': '!=',
    '≤': '<=',
    '≥': '>=',
    STARTS_WITH: 'starts with',
    'STARTS WITH': 'starts with',
    ENDS_WITH: 'ends with',
    'ENDS WITH': 'ends with',
    NOT_CONTAINS: 'not contains',
    'NOT CONTAINS': 'not contains',
    BETWEEN: 'between',
    AFTER: 'after',
    BEFORE: 'before',
    '=TRUE': '= TRUE',
    '=FALSE': '= FALSE',
  };
  const direct = aliases[trimmed];
  if (direct) return direct;
  const upper = aliases[trimmed.toUpperCase()];
  if (upper) return upper;
  return trimmed as FilterOperator;
}

/**
 * True when DSL is a grouped form (operator + conditions).
 */
export function isFilterGroup(dsl: FilterDsl): dsl is FilterGroup {
  return 'operator' in dsl && 'conditions' in dsl;
}

/**
 * Convert a flat condition list to a top-level AND group, or unwrap a
 * single condition. Used by the Advanced filter panel grid mode where
 * the editor manages a flat array, but the API/saved preset expects a
 * group.
 */
export function conditionsToDsl(
  conditions: FilterCondition[],
  operator: 'AND' | 'OR' = 'AND',
  scope?: FilterScope | null,
): FilterDsl | null {
  const first = conditions[0];
  if (first === undefined) return null;
  const normalizedScope = normalizeScope(scope);
  if (conditions.length === 1) {
    return normalizedScope ? { ...first, scope: normalizedScope } : first;
  }
  return normalizedScope
    ? { operator, conditions, scope: normalizedScope }
    : { operator, conditions };
}

/**
 * #2673 — drop empty strings / empty objects so a cleared selector never
 * serialises a dangling `scope: {}` into presets/URLs.
 */
export function normalizeScope(scope?: FilterScope | null): FilterScope | null {
  if (!scope) return null;
  const channel = scope.channel?.trim() || undefined;
  const locale = scope.locale?.trim() || undefined;
  if (!channel && !locale) return null;
  return { ...(channel ? { channel } : {}), ...(locale ? { locale } : {}) };
}

/**
 * #2673 — read the value-context scope off a DSL root (condition or group).
 */
export function extractScope(dsl: FilterDsl | null): FilterScope | null {
  if (!dsl) return null;
  return normalizeScope(dsl.scope ?? null);
}

/**
 * Inverse of {@link conditionsToDsl}: flatten the top-level group into a
 * flat condition list when possible (drops grouping). Returns `null`
 * when DSL contains nested groups — the grid mode editor cannot
 * represent them (VIEW-09b query mode handles those).
 */
export function dslToFlatConditions(dsl: FilterDsl | null): FilterCondition[] | null {
  if (!dsl) return [];
  if (!isFilterGroup(dsl)) {
    // #2673 — scope lives in the panel state, not on the flat condition.
    const { scope: _scope, ...bare } = dsl;
    return [bare];
  }

  const flat: FilterCondition[] = [];
  for (const cond of dsl.conditions) {
    if (isFilterGroup(cond)) return null; // nested → query mode required
    flat.push(cond);
  }
  return flat;
}
