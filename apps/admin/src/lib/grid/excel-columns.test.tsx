import { describe, expect, it } from 'vitest';

import type { ProductsGridRow } from '@/components/catalog/products-grid';

import { EXCEL_ATTR_KEY_PREFIX, toExcelColumns, toExcelRow } from './excel-columns';
import type { GridColumn } from './types';

/**
 * GRID-P1-04 (#2388) — model → Excel adapter: attribute columns are
 * read-only with plain-text row projections (clipboard TSV), system
 * columns keep the legacy keys so the SKU/name commit path is untouched.
 */

function col(overrides: Partial<GridColumn>): GridColumn {
  return {
    key: 'attr',
    source: 'attribute',
    type: 'text',
    label: { pl: 'Atrybut' },
    sortable: false,
    editable: false,
    position: 0,
    hidden: false,
    ...overrides,
  };
}

const ROW: ProductsGridRow = {
  id: 'p1',
  sku: 'SKU-1',
  name: 'Produkt',
  categories: ['buty', 'nike'],
  price: { amount: 99.99, currency: 'PLN' },
  completenessPct: 80,
  syncStatusAggregate: 'green',
  enabled: true,
  status: 'published',
  parentId: null,
  variantAxis: null,
  updatedAt: '2026-07-09T10:00:00Z',
  attributesIndexed: {
    brand: { option_code: 'nike' },
    weight: { value: 1.5 },
  },
};

const COLUMNS: GridColumn[] = [
  col({ key: 'code', source: 'system', type: 'system_identifier', label: { pl: 'SKU' } }),
  col({ key: '__name', source: 'system', type: 'view_name', label: { pl: 'Nazwa' } }),
  col({ key: 'brand', type: 'select', label: { pl: 'Marka' } }),
  col({ key: 'weight', type: 'number', label: { pl: 'Waga' } }),
  col({ key: 'hidden_one', type: 'text', hidden: true }),
];

const OPTION_LABELS = { brand: { nike: { pl: 'Nike', en: 'Nike' } } };

describe('toExcelColumns', () => {
  it('maps system keys to legacy row fields and attribute keys to prefixed projections', () => {
    const excel = toExcelColumns(COLUMNS, 'pl', OPTION_LABELS);

    expect(excel.map((c) => c.key)).toEqual([
      'sku',
      'name',
      `${EXCEL_ATTR_KEY_PREFIX}brand`,
      `${EXCEL_ATTR_KEY_PREFIX}weight`,
    ]);
    expect(excel[0]?.readOnly).toBe(true);
    expect(excel[1]?.readOnly).toBe(false); // name stays editable (legacy commit path)
    expect(excel[2]?.readOnly).toBe(true); // attribute columns read-only until GRID-P6-02
    expect(excel[2]?.label).toBe('Marka');
    expect(excel[2]?.renderDisplay).toBeDefined();
  });

  it('skips hidden columns', () => {
    const excel = toExcelColumns(COLUMNS, 'pl', {});
    expect(excel.some((c) => c.key === `${EXCEL_ATTR_KEY_PREFIX}hidden_one`)).toBe(false);
  });
});

describe('toExcelRow', () => {
  it('projects attribute display strings (option labels applied) for clipboard reads', () => {
    const row = toExcelRow(ROW, COLUMNS, 'pl', OPTION_LABELS);

    expect(row[`${EXCEL_ATTR_KEY_PREFIX}brand`]).toBe('Nike');
    expect(row[`${EXCEL_ATTR_KEY_PREFIX}weight`]).toBe('1.5');
    expect(row.sku).toBe('SKU-1');
    expect(row.categoriesDisplay).toBe('buty, nike');
  });

  it('degrades to option codes and empty strings without crashing on garbage', () => {
    const row = toExcelRow(
      { ...ROW, attributesIndexed: { brand: { totally: 'weird' } } },
      COLUMNS,
      'pl',
      {},
    );

    expect(typeof row[`${EXCEL_ATTR_KEY_PREFIX}brand`]).toBe('string');
    expect(row[`${EXCEL_ATTR_KEY_PREFIX}weight`]).toBe('');
  });
});
