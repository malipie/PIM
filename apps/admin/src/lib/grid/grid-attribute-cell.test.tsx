import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { GridAttributeCell, gridCellAlignment } from './grid-attribute-cell';
import type { GridColumn } from './types';

/**
 * GRID-P1-02 (#2386) — every attribute type renders a readable cell
 * (no `[object Object]`, option labels over codes, `—` for empty) and
 * garbage input never crashes the row.
 */

function column(overrides: Partial<GridColumn>): GridColumn {
  return {
    key: 'attr',
    source: 'attribute',
    type: 'text',
    label: { pl: 'Atrybut' },
    sortable: false,
    position: 0,
    hidden: false,
    ...overrides,
  };
}

describe('GridAttributeCell', () => {
  it('renders text with a full-value tooltip', () => {
    render(
      <GridAttributeCell
        column={column({ type: 'text' })}
        attributesIndexed={{ attr: { value: 'Nike Air' } }}
      />,
    );
    expect(screen.getByTitle('Nike Air')).toHaveTextContent('Nike Air');
  });

  it('renders select option labels for the UI locale, falling back to the code', () => {
    const { rerender } = render(
      <GridAttributeCell
        column={column({ key: 'color', type: 'select' })}
        attributesIndexed={{ color: { option_code: 'red' } }}
        optionLabels={{ color: { red: { pl: 'Czerwony', en: 'Red' } } }}
      />,
    );
    expect(screen.getByText('Czerwony')).toBeInTheDocument();

    rerender(
      <GridAttributeCell
        column={column({ key: 'color', type: 'select' })}
        attributesIndexed={{ color: { option_code: 'unknown_code' } }}
        optionLabels={{ color: {} }}
      />,
    );
    expect(screen.getByText('unknown_code')).toBeInTheDocument();
  });

  it('renders multiselect chips with a +N overflow tooltip listing the rest', () => {
    render(
      <GridAttributeCell
        column={column({ key: 'tags', type: 'multiselect' })}
        attributesIndexed={{ tags: { option_codes: ['a', 'b', 'c', 'd', 'e'] } }}
        optionLabels={{ tags: { d: { pl: 'Delta' }, e: { pl: 'Echo' } } }}
      />,
    );
    expect(screen.getByText('a')).toBeInTheDocument();
    expect(screen.getByText('+2')).toBeInTheDocument();
    expect(screen.getByTitle('Delta, Echo')).toBeInTheDocument();
    expect(screen.queryByText('d')).not.toBeInTheDocument();
  });

  it('formats price, number, boolean badge and date', () => {
    const { rerender, container } = render(
      <GridAttributeCell
        column={column({ type: 'price' })}
        attributesIndexed={{ attr: { amount: 99.99, currency: 'PLN' } }}
      />,
    );
    expect(container.textContent).toContain('99,99');
    expect(container.textContent).toContain('zł');

    rerender(
      <GridAttributeCell
        column={column({ type: 'number' })}
        attributesIndexed={{ attr: { value: 1234.5 } }}
      />,
    );
    expect(container.textContent).toContain('1234,5');

    rerender(
      <GridAttributeCell
        column={column({ type: 'boolean' })}
        attributesIndexed={{ attr: { value: true } }}
      />,
    );
    expect(screen.getByText('Tak')).toBeInTheDocument();

    rerender(
      <GridAttributeCell
        column={column({ type: 'date' })}
        attributesIndexed={{ attr: { value: '2026-07-09' } }}
      />,
    );
    expect(container.textContent).toMatch(/2026/);
    expect(container.textContent).not.toContain('[object Object]');
  });

  it('renders an em-dash for missing values and survives garbage envelopes', () => {
    const { rerender, container } = render(
      <GridAttributeCell column={column({ type: 'text' })} attributesIndexed={undefined} />,
    );
    expect(screen.getByText('—')).toBeInTheDocument();

    rerender(
      <GridAttributeCell
        column={column({ type: 'select' })}
        attributesIndexed={{ attr: { totally: { unexpected: ['shape'] } } }}
      />,
    );
    expect(container.textContent).not.toContain('[object Object]');

    rerender(
      <GridAttributeCell
        column={column({ type: 'unknown_future_type' })}
        attributesIndexed={{ attr: { value: 'raw' } }}
      />,
    );
    expect(screen.getByText('raw')).toBeInTheDocument();
  });

  it('renders a fixed-size asset placeholder without layout-shifting fetches', () => {
    render(
      <GridAttributeCell
        column={column({ key: 'gallery', type: 'asset' })}
        attributesIndexed={{ gallery: { option_codes: ['a1', 'a2'] } }}
      />,
    );
    expect(screen.getByText('2 pliki')).toBeInTheDocument();
  });
});

describe('gridCellAlignment', () => {
  it('right-aligns numeric readings only', () => {
    expect(gridCellAlignment({ type: 'number' })).toBe('right');
    expect(gridCellAlignment({ type: 'price' })).toBe('right');
    expect(gridCellAlignment({ type: 'metric' })).toBe('right');
    expect(gridCellAlignment({ type: 'text' })).toBe('left');
    expect(gridCellAlignment({ type: 'select' })).toBe('left');
  });
});
