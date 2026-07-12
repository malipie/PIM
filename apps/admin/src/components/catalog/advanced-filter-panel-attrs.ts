import type { FILTER_OPERATORS_BY_TYPE } from '@/lib/filters/filter-dsl';

/**
 * Static attribute catalog + type-mapping helpers for
 * {@link ./advanced-filter-panel.tsx}. Extracted (no behaviour change) to keep
 * the panel component under the admin max-lines guard — these are pure data
 * and a pure mapping function, imported back by the panel.
 */

export type PanelAttr = {
  code: string;
  name: string;
  type: keyof typeof FILTER_OPERATORS_BY_TYPE;
  star?: boolean;
};

export const FIRST_PANEL_ATTR: PanelAttr = {
  code: 'brand',
  name: 'Marka',
  type: 'relation',
  star: true,
};

/**
 * DASH-01 (#2249) — system list columns that are filterable in the search
 * index (IndexSettingsTemplate) but are not Attribute entities, so the
 * live `/api/attributes` fetch never returns them. They are unioned into
 * the effective catalog below; without this a condition seeded on
 * `completeness_pct` (dashboard deep-links) rendered with text operators
 * once the live set replaced the static fallback.
 */
export const SYSTEM_PANEL_ATTRS: ReadonlyArray<PanelAttr> = [
  { code: 'completeness_pct', name: 'Completeness %', type: 'number', star: true },
  { code: 'enabled', name: 'Aktywny', type: 'boolean', star: true },
  // #2526 — editorial state (draft/review/published/archived) as a
  // filterable system field: users scope exports/feeds/lists by status.
  // `select` gives =, !=, IN, NOT IN; the value picker special-cases the
  // fixed enum (BulkValueInput) so it needs no /api/attributes options row.
  { code: 'status', name: 'Status', type: 'select', star: true },
];

/**
 * Loading/empty fallback catalog. Since #1354 the live filterable
 * attribute set (fetched in the panel) is the source of truth for type-badge /
 * operator inference; this static list only fills the gap while that
 * fetch is in flight (or returns nothing) so the panel never renders a
 * dead picker on first open. An explicit `panelAttrs` prop still wins.
 */
export const PANEL_ATTRS: ReadonlyArray<PanelAttr> = [
  FIRST_PANEL_ATTR,
  { code: 'category', name: 'Kategoria', type: 'relation', star: true },
  ...SYSTEM_PANEL_ATTRS,
  { code: 'price', name: 'Cena bazowa', type: 'metric', star: true },
  { code: 'stock', name: 'Stan magazynowy', type: 'number' },
  { code: 'main_image', name: 'Główne zdjęcie', type: 'asset' },
  { code: 'description.pl', name: 'Opis · PL', type: 'text' },
  { code: 'description.en', name: 'Opis · EN', type: 'text' },
  { code: 'meta_description', name: 'Meta description', type: 'text' },
  { code: 'tags', name: 'Tagi', type: 'multiselect' },
];

/**
 * #1354 — map the backend AttributeType enum onto the panel's operator
 * buckets. Types without a dedicated bucket (color, email, identifier,
 * textarea) behave as plain string equality filters → `text`.
 */
const FILTER_TYPE_BY_ATTR_TYPE: Record<string, keyof typeof FILTER_OPERATORS_BY_TYPE> = {
  text: 'text',
  wysiwyg: 'wysiwyg',
  textarea: 'text',
  number: 'number',
  metric: 'metric',
  price: 'price',
  date: 'date',
  datetime: 'datetime',
  select: 'select',
  multiselect: 'multiselect',
  boolean: 'boolean',
  relation: 'relation',
  reference: 'reference',
  asset: 'asset',
  color: 'text',
  email: 'text',
  identifier: 'text',
};

export function toFilterType(attrType: string | undefined): keyof typeof FILTER_OPERATORS_BY_TYPE {
  return (attrType !== undefined ? FILTER_TYPE_BY_ATTR_TYPE[attrType] : undefined) ?? 'text';
}
