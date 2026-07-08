import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { BulkValueInput } from '@/components/catalog/bulk-wizard/bulk-value-input';

/**
 * #2311 — the shared typed value input renders a control matching the
 * attribute type instead of a bare text box. These cases cover the
 * fetch-free branches (date / datetime / color / boolean); select and
 * multiselect are exercised end-to-end via the advanced filter panel and
 * bulk wizard against the real options endpoint.
 */
describe('BulkValueInput typed controls', () => {
  it('renders a native date picker for a date attribute', () => {
    render(<BulkValueInput attrCode="release_date" attrType="date" value="" onChange={vi.fn()} />);
    const input = document.querySelector('input[type="date"]');
    expect(input).not.toBeNull();
  });

  it('renders a datetime-local picker for a datetime attribute', () => {
    render(<BulkValueInput attrCode="updated_at" attrType="datetime" value="" onChange={vi.fn()} />);
    const input = document.querySelector('input[type="datetime-local"]');
    expect(input).not.toBeNull();
  });

  it('renders a colour picker plus hex field for a color attribute', () => {
    const onChange = vi.fn();
    render(<BulkValueInput attrCode="swatch" attrType="color" value="#ff0000" onChange={onChange} />);
    const colorInput = document.querySelector('input[type="color"]');
    expect(colorInput).not.toBeNull();
    fireEvent.change(colorInput as HTMLInputElement, { target: { value: '#00ff00' } });
    expect(onChange).toHaveBeenCalledWith('#00ff00');
  });

  it('renders a Tak/Nie toggle for a boolean attribute and emits a boolean', () => {
    const onChange = vi.fn();
    render(<BulkValueInput attrCode="in_stock" attrType="boolean" value={false} onChange={onChange} />);
    fireEvent.click(screen.getByText('Tak'));
    expect(onChange).toHaveBeenCalledWith(true);
  });

  it('falls back to a plain text input for an unknown type', () => {
    render(<BulkValueInput attrCode="note" attrType={undefined} value="" onChange={vi.fn()} />);
    const inputs = document.querySelectorAll('input');
    expect(inputs.length).toBeGreaterThan(0);
    expect(document.querySelector('input[type="color"]')).toBeNull();
  });
});
