/**
 * #2740 — tiny runtime narrowing helpers for payloads that arrive as `unknown`
 * (Refine's `useCustomMutation` data, wire rows typed `Record<string, unknown>`).
 *
 * The API Configurator screens used to cast these with `as unknown as X`, which
 * the compiler accepts and runtime does not check — a backend response shape
 * change (hydra envelope vs bare array, a renamed field) passed typecheck and
 * blew up in the user's browser. These guards validate the shape the callers
 * actually depend on and let them fall back or surface an error instead.
 */

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Narrow to `T` when every listed key is present, else null (caller decides the fallback). */
export function objectWithKeys<T extends object>(
  value: unknown,
  keys: ReadonlyArray<keyof T>,
): T | null {
  if (!isRecord(value)) return null;
  for (const key of keys) {
    if (!((key as string) in value)) return null;
  }
  return value as T;
}
