import type { ExcelColumn } from './excel-like-grid';

/**
 * GRID-P6-03 (#2402) — coerce a raw string (typed cell edit or pasted
 * TSV) to the column's value type. Returns `{ ok: false }` when the
 * input cannot be parsed so paste can reject-and-report it; select maps
 * by option label OR code. Shared by single-cell commit and paste so
 * both validate identically.
 */
export function coerceExcelValue<T extends Record<string, unknown>>(
  col: ExcelColumn<T>,
  raw: string,
): { ok: true; value: unknown } | { ok: false } {
  if (raw === '') return { ok: true, value: null };
  if (col.type === 'number') {
    const parsed = Number.parseFloat(raw);
    return Number.isNaN(parsed) ? { ok: false } : { ok: true, value: parsed };
  }
  if (col.type === 'boolean') {
    const t = raw.trim().toLowerCase();
    if (['true', '1', 'tak', 'yes', '✓'].includes(t)) return { ok: true, value: true };
    if (['false', '0', 'nie', 'no', '✗'].includes(t)) return { ok: true, value: false };
    return { ok: false };
  }
  if (col.type === 'select') {
    const options = col.selectOptions ?? [];
    const byCode = options.find((o) => o.code === raw.trim());
    if (byCode !== undefined) return { ok: true, value: byCode.code };
    const byLabel = options.find((o) => o.label.toLowerCase() === raw.trim().toLowerCase());
    if (byLabel !== undefined) return { ok: true, value: byLabel.code };
    return { ok: false };
  }
  return { ok: true, value: raw };
}
