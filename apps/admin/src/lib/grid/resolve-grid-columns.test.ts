import { describe, expect, it } from 'vitest';

import type { ListSchemaResponse } from '@/hooks/use-list-schema';

import { FALLBACK_GRID_COLUMNS, resolveGridColumns } from './resolve-grid-columns';

/**
 * GRID-P1-01 (#2385) — merge matrix for the pure resolver: schema-only,
 * overrides (hidden / reorder / width), RBAC-dropped override keys and
 * the no-schema fallback.
 */

function schemaWith(columns: ListSchemaResponse['columns']): ListSchemaResponse {
  return {
    objectType: {
      id: 'ot-1',
      code: 'product',
      kind: 'product',
      label: { pl: 'Produkt' },
      is_categorizable: true,
      has_variants: true,
      has_multimedia: true,
      expose_to_main_menu: true,
    },
    columns,
    filterableAttributes: [],
    searchableAttributes: [],
  };
}

const SCHEMA = schemaWith([
  {
    key: 'brand',
    type: 'select',
    label: { pl: 'Marka' },
    position: 4,
    sortable: true,
    system: false,
  },
  {
    key: 'code',
    type: 'system_identifier',
    label: { pl: 'Kod' },
    position: 0,
    sortable: true,
    system: true,
  },
  {
    key: 'status',
    type: 'system_status',
    label: { pl: 'Status' },
    position: 1,
    sortable: true,
    system: true,
  },
]);

describe('resolveGridColumns', () => {
  it('orders schema columns by position and re-numbers contiguously', () => {
    const columns = resolveGridColumns(SCHEMA);

    expect(columns.map((column) => column.key)).toEqual(['code', 'status', 'brand']);
    expect(columns.map((column) => column.position)).toEqual([0, 1, 2]);
    expect(columns.every((column) => !column.hidden)).toBe(true);
    expect(columns[2] ?? {}).toMatchObject({ source: 'attribute', type: 'select', sortable: true });
    expect(columns[0]?.source).toBe('system');
  });

  it('applies override order, hidden and width; non-overridden columns follow schema order', () => {
    const columns = resolveGridColumns(SCHEMA, [
      { key: 'brand', width: 220 },
      { key: 'code', hidden: true },
    ]);

    expect(columns.map((column) => column.key)).toEqual(['brand', 'code', 'status']);
    expect(columns[0]?.width).toBe(220);
    expect(columns[1]?.hidden).toBe(true);
    expect(columns[2]?.hidden).toBe(false);
  });

  it('drops override keys absent from the schema (RBAC / deleted attribute)', () => {
    const columns = resolveGridColumns(SCHEMA, [
      { key: 'secret_margin', width: 300 },
      { key: 'brand' },
      { key: 'brand', hidden: true }, // duplicate entry — first one wins
    ]);

    expect(columns.map((column) => column.key)).toEqual(['brand', 'code', 'status']);
    expect(columns.some((column) => column.key === 'secret_margin')).toBe(false);
    expect(columns[0]?.hidden).toBe(false);
  });

  it('falls back to the system set when the schema is missing or empty', () => {
    expect(resolveGridColumns(undefined)).toEqual(FALLBACK_GRID_COLUMNS);
    expect(resolveGridColumns(null)).toEqual(FALLBACK_GRID_COLUMNS);
    expect(resolveGridColumns(schemaWith([]), [{ key: 'code', hidden: true }])).toEqual(
      FALLBACK_GRID_COLUMNS,
    );
  });

  it('survives malformed schema entries without throwing', () => {
    const malformed = schemaWith([
      // @ts-expect-error deliberate garbage — resolver must not trust the wire
      { key: 42, type: 'text', label: null, position: 0, sortable: true, system: false },
      {
        key: 'name',
        type: 'text',
        label: { pl: 'Nazwa' },
        position: 1,
        sortable: false,
        system: false,
      },
    ]);

    const columns = resolveGridColumns(malformed);
    expect(columns.map((column) => column.key)).toEqual(['name']);
  });
});
