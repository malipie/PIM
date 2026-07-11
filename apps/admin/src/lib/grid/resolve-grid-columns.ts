import type { ListSchemaResponse } from '@/hooks/use-list-schema';

import type { GridColumn, GridColumnOverride, ViewColumnSeed } from './types';

/**
 * GRID-P1-01 (#2385) — pure resolver: list-schema × user overrides →
 * ordered `GridColumn[]`. Kept side-effect free so Vitest can cover the
 * merge matrix without React or network.
 */

/**
 * Minimal system set used when the schema is unavailable (fetch error /
 * first paint before the query resolves). Mirrors the always-on system
 * columns the backend emits (`GetObjectTypeListSchemaHandler`), so the
 * views degrade to the pre-GRID column surface instead of rendering an
 * empty table.
 */
export const FALLBACK_GRID_COLUMNS: GridColumn[] = [
  {
    key: 'code',
    source: 'system',
    type: 'system_identifier',
    label: { pl: 'Kod', en: 'Code' },
    sortable: true,
    position: 0,
    hidden: false,
  },
  {
    key: 'status',
    source: 'system',
    type: 'system_status',
    label: { pl: 'Status', en: 'Status' },
    sortable: true,
    position: 1,
    hidden: false,
  },
  {
    key: 'completeness',
    source: 'system',
    type: 'system_completeness',
    label: { pl: 'Kompletność', en: 'Completeness' },
    sortable: true,
    position: 2,
    hidden: false,
  },
  {
    key: 'updatedAt',
    source: 'system',
    type: 'system_date',
    label: { pl: 'Zmodyfikowano', en: 'Modified' },
    sortable: true,
    position: 3,
    hidden: false,
  },
];

/**
 * Merges the server schema with user overrides.
 *
 * Rules:
 * - No schema → {@link FALLBACK_GRID_COLUMNS} (overrides are ignored:
 *   without the schema we cannot verify the keys are still permitted).
 * - Override entries whose key is absent from the schema are DROPPED —
 *   the schema is the RBAC-filtered allow-list (ULV-04b), localStorage
 *   must never widen it.
 * - Array order of `overrides` defines the leading column order;
 *   schema columns without an override follow in schema `position`
 *   order. `position` in the result is re-numbered to be contiguous.
 * - `hidden` defaults to false — the schema only carries columns meant
 *   to be shown (`system` + `show_in_list=true`).
 * - `viewColumns` (GRID-P1-03) are view-owned derived columns inserted
 *   after their `after` anchor; a seed whose key collides with a schema
 *   column is skipped (the schema wins).
 */
export function resolveGridColumns(
  schema: ListSchemaResponse | undefined | null,
  overrides: GridColumnOverride[] = [],
  viewColumns: ViewColumnSeed[] = [],
): GridColumn[] {
  const schemaColumns = schema?.columns;
  if (!Array.isArray(schemaColumns) || schemaColumns.length === 0) {
    return FALLBACK_GRID_COLUMNS;
  }

  const base = new Map<string, GridColumn>();
  const sorted = [...schemaColumns].sort((a, b) => a.position - b.position);
  for (const column of sorted) {
    if (typeof column?.key !== 'string' || column.key.length === 0) continue;
    base.set(column.key, {
      key: column.key,
      source: column.system ? 'system' : 'attribute',
      type: column.type,
      label: column.label ?? {},
      sortable: Boolean(column.sortable),
      position: base.size,
      // GRID-P3-01 full-schema mode: non-default attributes (no
      // `show_in_list`) start hidden — the manager reveals them.
      hidden: column.default === false,
      ...(column.group !== undefined
        ? {
            group:
              column.group === null ? null : { code: column.group.code, label: column.group.label },
          }
        : {}),
    });
  }

  for (const seed of viewColumns) {
    if (typeof seed?.key !== 'string' || seed.key.length === 0 || base.has(seed.key)) continue;
    const column: GridColumn = {
      key: seed.key,
      source: 'system',
      type: seed.type,
      label: seed.label ?? {},
      sortable: Boolean(seed.sortable),
      position: 0, // re-numbered below
      hidden: false,
    };
    const entries = [...base.values()];
    const anchor = seed.after !== undefined ? entries.findIndex((c) => c.key === seed.after) : -1;
    if (anchor === -1) {
      base.set(column.key, column);
    } else {
      entries.splice(anchor + 1, 0, column);
      base.clear();
      for (const entry of entries) base.set(entry.key, entry);
    }
  }

  const ordered: GridColumn[] = [];
  const consumed = new Set<string>();
  for (const override of overrides) {
    const column = base.get(override.key);
    // Unknown key = attribute removed from the ObjectType or newly
    // RBAC-restricted since the pref was written — silently skip.
    if (!column || consumed.has(override.key)) continue;
    consumed.add(override.key);
    ordered.push({
      ...column,
      hidden: override.hidden ?? false,
      width: override.width,
      pinned: override.pinned,
    });
  }
  for (const column of base.values()) {
    if (!consumed.has(column.key)) ordered.push(column);
  }

  return ordered.map((column, index) => ({ ...column, position: index }));
}
