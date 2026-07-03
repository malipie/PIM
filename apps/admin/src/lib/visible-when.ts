/**
 * DP-08 (#2039) — client-side mirror of the backend
 * `VisibleWhenRuleEvaluator` (UI-08.8 #263): evaluates the MVP
 * `{field, operator: 'equals', value}` rule against current form values.
 *
 * Semantics kept 1:1 with PHP:
 *   - missing/empty field value → NOT visible (the dependent attribute
 *     stays hidden until its driver has a value),
 *   - scalars compared strictly, arrays deep-equal regardless of order,
 *   - envelope shapes ({value}|{option_code}|{option_codes}) unwrap to
 *     their canonical scalar before comparing,
 *   - unknown operators → visible (forward-compat lenience, same as BE).
 */

export interface VisibleWhenRule {
  field: string;
  operator: string;
  value: unknown;
}

export function parseVisibleWhen(raw: unknown): VisibleWhenRule | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const rule = raw as Record<string, unknown>;
  if (typeof rule.field !== 'string' || rule.field === '') return null;
  if (typeof rule.operator !== 'string') return null;
  if (!('value' in rule)) return null;
  return { field: rule.field, operator: rule.operator, value: rule.value };
}

/**
 * @param raw           the junction's `visible_when` payload (or null/undefined)
 * @param resolveField  reads the CURRENT value of an attribute code (form draft)
 */
export function isVisibleWhen(raw: unknown, resolveField: (code: string) => unknown): boolean {
  const rule = parseVisibleWhen(raw);
  if (rule === null) return true; // no (or malformed) rule → always visible

  const current = extractScalar(resolveField(rule.field));
  if (current === undefined || current === null || current === '') return false;

  if (rule.operator === 'equals') {
    return deepEquals(current, rule.value);
  }

  // Faza 1+ operators land here; until then unknown operators are lenient.
  return true;
}

function extractScalar(payload: unknown): unknown {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    return payload;
  }
  const envelope = payload as Record<string, unknown>;
  if ('value' in envelope) return envelope.value;
  if ('option_code' in envelope) return envelope.option_code;
  if ('option_codes' in envelope) return envelope.option_codes;
  return payload;
}

function deepEquals(left: unknown, right: unknown): boolean {
  if (Array.isArray(left) && Array.isArray(right)) {
    if (left.length !== right.length) return false;
    const l = [...left].sort();
    const r = [...right].sort();
    return l.every((entry, i) => deepEquals(entry, r[i]));
  }
  return left === right;
}
