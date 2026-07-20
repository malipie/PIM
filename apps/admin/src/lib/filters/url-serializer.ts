import {
  extractScope,
  type FilterCondition,
  type FilterDsl,
  type FilterGroup,
  type FilterOperator,
  isFilterGroup,
  normaliseOperator,
  normalizeScope,
  operatorRequiresArray,
  operatorRequiresRange,
  operatorRequiresValue,
} from './filter-dsl';

/**
 * VIEW-10 (#538) — bi-directional URL serializer FE mirror.
 *
 * Mirror of `App\Catalog\Application\Filter\FilterUrlSerializer` in BE.
 * Two URL flavours produced:
 *   - **Flat**: `filter[brand][op]=&filter[brand][value]=Festo` for shareable links.
 *   - **Base64 blob**: `?q=<base64-json>` for nested DSL groups.
 *
 * Soft limit 4096 bytes for base64 blob; longer payloads should fail
 * loudly at the backend rather than being silently truncated.
 *
 * Shorthand op codes (used in compressed URLs): eq, neq, lt, gt, lte,
 * gte, in, notin, contains, ncontains, startsw, endsw, between, empty,
 * nempty, true, false, after, before.
 */

export const MAX_BLOB_BYTES = 4096;

const SHORTHAND_OPS: Record<string, FilterOperator> = {
  eq: '=',
  neq: '!=',
  lt: '<',
  gt: '>',
  lte: '<=',
  gte: '>=',
  in: 'IN',
  notin: 'NOT IN',
  contains: 'contains',
  ncontains: 'not contains',
  startsw: 'starts with',
  endsw: 'ends with',
  between: 'between',
  empty: 'IS EMPTY',
  nempty: 'IS NOT EMPTY',
  true: '= TRUE',
  false: '= FALSE',
  after: 'after',
  before: 'before',
};

const OP_TO_SHORTHAND: Record<string, string> = {
  '=': 'eq',
  '!=': 'neq',
  '<': 'lt',
  '>': 'gt',
  '<=': 'lte',
  '>=': 'gte',
  IN: 'in',
  'NOT IN': 'notin',
  contains: 'contains',
  'not contains': 'ncontains',
  'starts with': 'startsw',
  'ends with': 'endsw',
  between: 'between',
  'IS EMPTY': 'empty',
  'IS NOT EMPTY': 'nempty',
  '= TRUE': 'true',
  '= FALSE': 'false',
  after: 'after',
  before: 'before',
};

/**
 * Encode DSL to a base64 JSON blob. Throws on payloads >MAX_BLOB_BYTES.
 */
export function dslToBase64(dsl: FilterDsl): string {
  const json = JSON.stringify(dsl);
  const blob = typeof btoa === 'function' ? btoa(unescape(encodeURIComponent(json))) : '';
  if (blob.length > MAX_BLOB_BYTES) {
    throw new Error(`Filter blob exceeds ${MAX_BLOB_BYTES} bytes`);
  }
  return blob;
}

/**
 * Decode base64 JSON blob into a DSL. Throws on invalid payload.
 */
export function base64ToDsl(blob: string): FilterDsl {
  if (blob.length > MAX_BLOB_BYTES) {
    throw new Error(`Filter blob exceeds ${MAX_BLOB_BYTES} bytes`);
  }
  try {
    const raw = typeof atob === 'function' ? decodeURIComponent(escape(atob(blob))) : '';
    return JSON.parse(raw) as FilterDsl;
  } catch (e) {
    throw new Error(`Invalid base64 filter blob: ${e instanceof Error ? e.message : 'unknown'}`);
  }
}

/**
 * Serialize a flat (single-level) DSL to URLSearchParams. Nested groups
 * fall through to base64 blob via `dslToBase64` — caller should pick
 * the right flavour based on `isFilterGroup` + nested check.
 *
 * Output shape: `filter[attr][op]=<shorthand>&filter[attr][value]=...`.
 */
export function dslToUrlParams(dsl: FilterDsl | null): URLSearchParams {
  const params = new URLSearchParams();
  if (!dsl) return params;

  const flat = flattenSingleLevel(dsl);
  if (!flat) {
    // nested group → caller uses base64
    return params;
  }

  for (const cond of flat) {
    const opShort = OP_TO_SHORTHAND[cond.op] ?? cond.op;
    params.set(`filter[${cond.attr}][op]`, opShort);
    if (cond.value !== undefined && cond.value !== null) {
      if (Array.isArray(cond.value)) {
        params.set(`filter[${cond.attr}][value]`, cond.value.join(','));
      } else {
        params.set(`filter[${cond.attr}][value]`, String(cond.value));
      }
    }
  }

  // #2673 — value context rides dedicated `fscope[...]` params so a
  // shareable flat URL round-trips the panel's channel/locale selector.
  const scope = extractScope(dsl);
  if (scope?.channel) params.set('fscope[channel]', scope.channel);
  if (scope?.locale) params.set('fscope[locale]', scope.locale);

  return params;
}

/**
 * Parse URLSearchParams back into a DSL. Empty params → null.
 */
export function urlParamsToDsl(params: URLSearchParams): FilterDsl | null {
  const conditions = new Map<string, FilterCondition>();

  for (const [key, value] of params.entries()) {
    const match = key.match(/^filter\[([^\]]+)\]\[(op|value)\]$/);
    if (!match) continue;
    const attr = match[1];
    const part = match[2];
    if (!attr || !part) continue;

    const existing = conditions.get(attr) ?? { attr, op: '=' as FilterOperator };
    if (part === 'op') {
      const lower = value.toLowerCase().replace(/\s/g, '');
      existing.op = SHORTHAND_OPS[lower] ?? normaliseOperator(value);
    } else if (part === 'value') {
      existing.value = parseValueForOp(value, existing.op);
    }
    conditions.set(attr, existing);
  }

  // Also support compressed shorthand `?brand=Festo` (no `filter[]` wrapper).
  for (const [key, value] of params.entries()) {
    if (key.startsWith('filter[')) continue;
    // #2673 — `fscope[...]` carries the value context, never a condition.
    if (key.startsWith('fscope[')) continue;
    if (key === 'q' || key === 'smart_preset' || key === 'page' || key === 'perPage') continue;
    if (conditions.has(key)) continue;
    if (value.includes(',')) {
      conditions.set(key, {
        attr: key,
        op: 'IN' as FilterOperator,
        value: value
          .split(',')
          .map((v) => v.trim())
          .filter(Boolean),
      });
    } else {
      conditions.set(key, { attr: key, op: '=' as FilterOperator, value });
    }
  }

  const scope = normalizeScope({
    channel: params.get('fscope[channel]') ?? undefined,
    locale: params.get('fscope[locale]') ?? undefined,
  });

  const list = Array.from(conditions.values());
  const first = list[0];
  if (first === undefined) return null;
  const withScope = <T extends FilterDsl>(dsl: T): T => (scope ? { ...dsl, scope } : dsl);
  if (list.length === 1) return withScope(first);
  return withScope({ operator: 'AND', conditions: list });
}

function flattenSingleLevel(dsl: FilterDsl): FilterCondition[] | null {
  if (!isFilterGroup(dsl)) return [dsl];
  const flat: FilterCondition[] = [];
  for (const cond of dsl.conditions) {
    if (isFilterGroup(cond)) return null;
    flat.push(cond);
  }
  return flat;
}

function parseValueForOp(raw: string, op: FilterOperator): FilterCondition['value'] {
  if (!operatorRequiresValue(op)) return undefined;
  if (operatorRequiresArray(op)) {
    return raw
      .split(',')
      .map((v) => v.trim())
      .filter(Boolean);
  }
  if (operatorRequiresRange(op)) {
    const parts = raw
      .split(',')
      .map((v) => v.trim())
      .filter(Boolean);
    if (parts.length === 2) return parts;
  }
  return raw;
}

/**
 * Helper: produce a shareable URL for the current filter state.
 * Picks `?q=<base64>` for nested groups, flat `filter[...]` otherwise.
 */
export function dslToSearchString(dsl: FilterDsl | null): string {
  if (!dsl) return '';
  const flat = flattenSingleLevel(dsl);
  if (flat) {
    return dslToUrlParams(dsl).toString();
  }
  const params = new URLSearchParams();
  params.set('q', dslToBase64(dsl));
  return params.toString();
}

/**
 * Helper: extract DSL from a query string (incl. `?q=<base64>` fallback).
 */
export function searchStringToDsl(search: string): FilterDsl | null {
  const params = new URLSearchParams(search);
  const blob = params.get('q');
  if (blob) {
    try {
      return base64ToDsl(blob);
    } catch {
      return null;
    }
  }
  return urlParamsToDsl(params);
}

/**
 * Re-export for callers that mix this module with `filter-dsl`.
 */
export type { FilterCondition, FilterDsl, FilterGroup };
