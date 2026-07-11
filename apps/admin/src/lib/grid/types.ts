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

/** GRID epic column model — see feature-grid-tickets.md. */
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
  /** GRID-P6-01 — inline-editable in the Excel view. */
  editable: boolean;
  /** Resolved display order (0-based, contiguous). */
  position: number;
  hidden: boolean;
  /** User-set width in px; undefined = per-type default (view decides). */
  width?: number;
  /** Reserved for GRID-P2-02 — only the identifier column pins in MVP. */
  pinned?: boolean;
  /** GRID-P3-02 — owning AttributeGroup (full-schema mode), for the manager. */
  group?: { code: string; label: Record<string, string> } | null;
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

/**
 * GRID-P1-03/04 — a view-owned data column that does not exist in the
 * list-schema (relational/derived data: categories, sync aggregate,
 * derived price/name fallbacks). Merged into the model right after
 * `after` (or appended) and treated like any other column by overrides
 * and the column manager. Keys are `__`-prefixed by convention so they
 * can never shadow an attribute code.
 */
export interface ViewColumnSeed {
  key: string;
  /** View-dispatched renderer id, e.g. `view_categories`. */
  type: string;
  label: Record<string, string>;
  /** Insert after this schema/view key; append when absent. */
  after?: string;
  sortable?: boolean;
}
