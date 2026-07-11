import { describe, expect, it } from 'vitest';

import {
  autoFitWidth,
  clampColumnWidth,
  moveColumn,
  overridesFromColumns,
  setColumnWidth,
  toggleColumnHidden,
} from './overrides';
import type { GridColumn } from './types';

/** GRID-P2-01/02 (#2389/#2390) — manager state helpers. */

function col(key: string, overrides: Partial<GridColumn> = {}): GridColumn {
  return {
    key,
    source: 'attribute',
    type: 'text',
    label: { pl: key },
    sortable: false,
    editable: false,
    position: 0,
    hidden: false,
    ...overrides,
  };
}

const COLS = [col('code', { source: 'system' }), col('a'), col('b'), col('c')];

describe('overridesFromColumns', () => {
  it('emits minimal entries preserving order', () => {
    const columns = [col('code'), col('a', { hidden: true }), col('b', { width: 200 })];
    expect(overridesFromColumns(columns)).toEqual([
      { key: 'code' },
      { key: 'a', hidden: true },
      { key: 'b', width: 200 },
    ]);
  });
});

describe('moveColumn', () => {
  it('swaps with the neighbour and re-numbers positions', () => {
    const next = moveColumn(COLS, 'b', -1);
    expect(next.map((column) => column.key)).toEqual(['code', 'b', 'a', 'c']);
    expect(next.map((column) => column.position)).toEqual([0, 1, 2, 3]);
  });

  it('never displaces the locked identifier from position 0', () => {
    expect(moveColumn(COLS, 'a', -1)).toBe(COLS); // would land on code
    expect(moveColumn(COLS, 'code', 1)).toBe(COLS);
    expect(moveColumn(COLS, 'c', 1)).toBe(COLS); // out of range
  });
});

describe('toggleColumnHidden / setColumnWidth', () => {
  it('toggles visibility but refuses to hide the identifier', () => {
    expect(toggleColumnHidden(COLS, 'a')[1]?.hidden).toBe(true);
    expect(toggleColumnHidden(COLS, 'code')).toBe(COLS);
  });

  it('sets width on the targeted column only', () => {
    const next = setColumnWidth(COLS, 'b', 240);
    expect(next[2]?.width).toBe(240);
    expect(next[1]?.width).toBeUndefined();
  });
});

describe('clampColumnWidth / autoFitWidth', () => {
  it('clamps to the usable range', () => {
    expect(clampColumnWidth(10)).toBe(60);
    expect(clampColumnWidth(9000)).toBe(640);
    expect(clampColumnWidth(240.4)).toBe(240);
  });

  it('auto-fit grows with content and stays clamped', () => {
    const narrow = autoFitWidth('Ab', ['x', 'yy']);
    const wide = autoFitWidth('Label', ['some very long attribute value in a cell']);
    expect(narrow).toBeLessThan(wide);
    expect(wide).toBeLessThanOrEqual(640);
    expect(narrow).toBeGreaterThanOrEqual(60);
  });
});
