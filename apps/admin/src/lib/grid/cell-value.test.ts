import { describe, expect, it } from 'vitest';

import { extractGridCellValue } from './cell-value';

/**
 * GRID-P1-02 (#2386) — the extractor must map every canonical envelope
 * (ADR-0019 / docs/api/jsonb-schemas.md) and never throw on garbage.
 */

describe('extractGridCellValue', () => {
  it('maps canonical envelopes', () => {
    expect(extractGridCellValue({ value: 'Nike' }, 'pl')).toEqual({ kind: 'text', value: 'Nike' });
    expect(extractGridCellValue({ value: 42 }, 'pl')).toEqual({ kind: 'number', value: 42 });
    expect(extractGridCellValue({ value: true }, 'pl')).toEqual({ kind: 'boolean', value: true });
    expect(extractGridCellValue({ option_code: 'red' }, 'pl')).toEqual({
      kind: 'options',
      codes: ['red'],
    });
    expect(extractGridCellValue({ option_codes: ['red', 'blue'] }, 'pl')).toEqual({
      kind: 'options',
      codes: ['red', 'blue'],
    });
    expect(extractGridCellValue({ amount: 99.99, currency: 'PLN' }, 'pl')).toEqual({
      kind: 'price',
      amount: 99.99,
      currency: 'PLN',
    });
    expect(extractGridCellValue({ amount: '12.50', currency: 'EUR' }, 'pl')).toEqual({
      kind: 'price',
      amount: 12.5,
      currency: 'EUR',
    });
  });

  it('resolves localizable/scopable maps for the UI locale with fallback', () => {
    expect(extractGridCellValue({ pl: 'Nazwa', en: 'Name' }, 'pl')).toEqual({
      kind: 'text',
      value: 'Nazwa',
    });
    expect(extractGridCellValue({ en: 'Name' }, 'pl')).toEqual({ kind: 'text', value: 'Name' });
    expect(extractGridCellValue({ value: { pl: 'Nazwa', en: 'Name' } }, 'en')).toEqual({
      kind: 'text',
      value: 'Name',
    });
    // channel-scoped numeric map — first non-empty wins when locale misses
    expect(extractGridCellValue({ shopify: 10, allegro: 15 }, 'pl')).toEqual({
      kind: 'number',
      value: 10,
    });
  });

  it('accepts already-unwrapped scalars (post-unwrapAttributesIndexed rows)', () => {
    expect(extractGridCellValue('Nike', 'pl')).toEqual({ kind: 'text', value: 'Nike' });
    expect(extractGridCellValue(7, 'pl')).toEqual({ kind: 'number', value: 7 });
    expect(extractGridCellValue(false, 'pl')).toEqual({ kind: 'boolean', value: false });
    expect(extractGridCellValue(['a', 'b'], 'pl')).toEqual({ kind: 'options', codes: ['a', 'b'] });
  });

  it('returns empty for null/undefined/blank and never throws on garbage', () => {
    expect(extractGridCellValue(null, 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue(undefined, 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue('   ', 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue({ value: null }, 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue({ option_code: 7 }, 'pl')).toEqual({ kind: 'empty' });
    // malformed writer (string instead of array) reads as one option
    expect(extractGridCellValue({ option_codes: 'red' }, 'pl')).toEqual({
      kind: 'options',
      codes: ['red'],
    });
    expect(extractGridCellValue({ option_codes: 42 }, 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue({ amount: 'not-a-number' }, 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue(Number.NaN, 'pl')).toEqual({ kind: 'empty' });
    expect(extractGridCellValue({ value: [Symbol('x')] } as unknown, 'pl')).toEqual({
      kind: 'empty',
    });
    expect(extractGridCellValue(() => 'fn', 'pl')).toEqual({ kind: 'empty' });
  });
});
