/**
 * GRID-P1-01 (#2385) — canonical column model for the universal object
 * list views (grid + Excel). Resolved from the ULV-03 list-schema
 * (`GET /api/object_types/{id}/list-schema`) merged with per-user
 * overrides (localStorage quick-prefs today; SavedView.config in M4).
 *
 * The model is the single source both views consume (GRID-P1-03/04) and
 * the column manager mutates (GRID-P2-01). RBAC is enforced upstream:
 * the schema never contains `restricted` attribute columns (ULV-04b),
 * and the resolver drops override entries whose key is absent from the
 * schema, so a stale localStorage entry can never resurrect a column
 * the server withheld.
 */

export type GridColumnSource = 'system' | 'attribute';

export interface GridColumn {
  /** Row/data key — system field name or attribute code. */
  key: string;
  source: GridColumnSource;
  /**
   * Backend list-schema type: `system_*` for system columns, otherwise
   * the attribute type (`text`, `number`, `select`, `price`, ...).
   */
  type: string;
  /** Localized label map (`{pl: ..., en: ...}`) straight from the schema. */
  label: Record<string, string>;
  sortable: boolean;
  /** Resolved display order (0-based, contiguous). */
  position: number;
  hidden: boolean;
  /** User-set width in px; undefined = per-type default (view decides). */
  width?: number;
  /** Reserved for GRID-P2-02 — only the identifier column pins in MVP. */
  pinned?: boolean;
}

/**
 * One user override entry. Array order = desired column order; columns
 * without an entry keep schema order after the overridden ones. The
 * shape deliberately matches the SavedView.config `columns` contract
 * planned in GRID-P4-01 (`{key, width?, pinned?}` + `hidden` for the
 * quick-pref layer).
 */
export interface GridColumnOverride {
  key: string;
  hidden?: boolean;
  width?: number;
  pinned?: boolean;
}
