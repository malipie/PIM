/**
 * `attributes_indexed` is the denormalised JSONB cache that the API exposes
 * as `attributesIndexed` (per-product / per-category / per-asset). Each
 * attribute key maps to an envelope `{ value, locale?, channel?, ... }` so
 * the backend can carry channel/locale overlays alongside the global
 * reading. The shape is canonical — see `AttributesIndexedRebuilder`
 * (apps/api/src/Catalog/Application/AttributesIndexedRebuilder.php) and
 * `ObjectValue::getValue()` for the writer side.
 *
 * The admin reads this map in many places (lists, detail page, variants
 * tab, asset picker, category trees). Treating the envelope as a plain
 * value silently fell back to the row code (SKU) every time, so PATCHes
 * looked like no-ops in the UI even though the backend persisted them.
 *
 * `unwrapAttributesIndexed` lifts `.value` to the top so consumers can
 * read attribute readings with the same `attrs.name` ergonomics they
 * already use. Non-envelope entries pass through unchanged so the helper
 * is safe to apply over data that has already been flattened.
 */
export function unwrapAttributesIndexed(
  raw: Record<string, unknown> | null | undefined,
): Record<string, unknown> {
  if (raw === null || raw === undefined) return {};
  const out: Record<string, unknown> = {};
  for (const [key, entry] of Object.entries(raw)) {
    if (entry !== null && typeof entry === 'object' && !Array.isArray(entry)) {
      const env = entry as Record<string, unknown>;
      // ADR-0019 canonical envelopes (IMP2-1.2 / #1464): selects carry
      // option_code, multiselects option_codes, prices {amount, currency}
      // (kept whole — price renderers need both fields).
      if ('value' in env) {
        out[key] = env.value;
      } else if ('option_code' in env) {
        out[key] = env.option_code;
      } else if ('option_codes' in env) {
        out[key] = env.option_codes;
      } else {
        out[key] = entry;
      }
    } else {
      out[key] = entry;
    }
  }
  return out;
}

/**
 * #2943 — an object's human name for pickers, lists and summaries.
 *
 * `name` may arrive as a plain string or, when the attribute is localizable
 * and the reading was written per locale, as a `{pl, en}` map. Both shapes
 * come out of the same `attributes_indexed` envelope, so callers that tested
 * only for `string` fell through to the technical code — which is how a
 * relation picker ended up listing `TW-001` instead of `Stanisław Lem`.
 *
 * Returns null when there is no usable reading, so the caller decides what
 * to fall back to (usually `code`).
 */
export function objectNameFromAttributes(
  raw: Record<string, unknown> | null | undefined,
  locale?: string,
): string | null {
  const name = unwrapAttributesIndexed(raw).name;
  if (typeof name === 'string') return name.trim() === '' ? null : name;
  if (typeof name === 'object' && name !== null && !Array.isArray(name)) {
    const byLocale = name as Record<string, unknown>;
    const preferred = locale === undefined ? undefined : byLocale[locale.split('-')[0] ?? locale];
    const candidates = [preferred, byLocale.pl, byLocale.en, ...Object.values(byLocale)];
    for (const candidate of candidates) {
      if (typeof candidate === 'string' && candidate.trim() !== '') return candidate;
    }
  }
  return null;
}
