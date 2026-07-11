/**
 * GRID-P1-02 (#2386) — pure extraction of a display value from one
 * `attributesIndexed` entry. The envelope shapes are canonical (ADR-0019,
 * docs/api/jsonb-schemas.md) but this reader must never throw on garbage:
 * stale caches, half-migrated rows and already-unwrapped maps
 * (`unwrapAttributesIndexed`) all flow through the same grids.
 */

export type GridCellValue =
  | { kind: 'empty' }
  | { kind: 'text'; value: string }
  | { kind: 'number'; value: number }
  | { kind: 'boolean'; value: boolean }
  | { kind: 'options'; codes: string[] }
  | { kind: 'price'; amount: number; currency: string };

const EMPTY: GridCellValue = { kind: 'empty' };

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function fromScalar(value: unknown): GridCellValue {
  if (value === null || value === undefined) return EMPTY;
  if (typeof value === 'boolean') return { kind: 'boolean', value };
  if (typeof value === 'number') {
    return Number.isFinite(value) ? { kind: 'number', value } : EMPTY;
  }
  if (typeof value === 'string') {
    return value.trim().length === 0 ? EMPTY : { kind: 'text', value };
  }
  if (Array.isArray(value)) {
    const codes = value.filter((item): item is string => typeof item === 'string');
    return codes.length === 0 ? EMPTY : { kind: 'options', codes };
  }
  return EMPTY;
}

/**
 * Picks a reading from a locale/channel map envelope
 * (`{"pl": "...", "en": "..."}` / `{"shopify": 10}`): the current UI
 * locale wins, then the first non-empty entry. The locale/channel
 * context switcher is out of the GRID epic — this fallback is the
 * documented MVP behaviour (backlog M1).
 */
function fromKeyedMap(map: Record<string, unknown>, locale: string): GridCellValue {
  const direct = fromScalar(map[locale]);
  if (direct.kind !== 'empty') return direct;
  for (const entry of Object.values(map)) {
    const candidate = fromScalar(entry);
    if (candidate.kind !== 'empty') return candidate;
  }
  return EMPTY;
}

/**
 * @param entry one value from `attributesIndexed[code]` — raw envelope or
 *   an already-unwrapped scalar (both are in circulation).
 * @param locale base UI locale (`i18n.language.split('-')[0]`).
 */
export function extractGridCellValue(entry: unknown, locale: string): GridCellValue {
  if (!isRecord(entry)) return fromScalar(entry);

  if ('value' in entry) {
    const inner = entry.value;
    // Localizable envelopes may nest the locale map under `value`.
    if (isRecord(inner)) return fromKeyedMap(inner, locale);
    return fromScalar(inner);
  }
  if ('option_code' in entry) {
    const code = entry.option_code;
    return typeof code === 'string' && code.length > 0 ? { kind: 'options', codes: [code] } : EMPTY;
  }
  if ('option_codes' in entry) {
    const codes = entry.option_codes;
    // Tolerant reading: a lone string (malformed writer) still shows as
    // one option instead of silently dropping the value.
    if (typeof codes === 'string' && codes.length > 0) return { kind: 'options', codes: [codes] };
    if (Array.isArray(codes)) return fromScalar(codes);
    return EMPTY;
  }
  if ('amount' in entry) {
    const amount =
      typeof entry.amount === 'number'
        ? entry.amount
        : typeof entry.amount === 'string'
          ? Number(entry.amount)
          : Number.NaN;
    if (!Number.isFinite(amount)) return EMPTY;
    const currency = typeof entry.currency === 'string' ? entry.currency : '';
    return { kind: 'price', amount, currency };
  }

  // No canonical marker → localizable/scopable map keyed by locale/channel.
  return fromKeyedMap(entry, locale);
}
