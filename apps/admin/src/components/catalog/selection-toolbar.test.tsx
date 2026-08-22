import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SelectionToolbar } from './selection-toolbar';

const noop = () => {};

describe('SelectionToolbar — capped selection', () => {
  it('says the limit in words, with both numbers', () => {
    // #2945 — it used to read "10 000 · wszystkie pasujące do filtru
    // zaznaczone (cross-page selection) · cap 10k", where 10 000 looked like
    // the match count rather than a ceiling.
    render(
      <SelectionToolbar
        mode="all-matching"
        perPageCount={50}
        matchingCount={50060}
        totalMatched={10000}
        capped
        onSelectAllMatching={noop}
        onClear={noop}
      />,
    );

    const message = screen.getByText(/więcej nie można zaznaczyć naraz/i);
    // Digits only: `toLocaleString('pl-PL')` groups with a non-breaking space
    // whose exact codepoint differs between ICU builds, and the assertion is
    // about the numbers being present, not about how they are spaced.
    const digits = (message.textContent ?? '').replace(/\D/g, '');
    expect(digits).toContain('10000');
    expect(digits).toContain('50060');
    // The developer jargon is gone.
    expect(screen.queryByText(/cross-page/i)).toBeNull();
    expect(screen.queryByText(/cap 10k/i)).toBeNull();
  });

  it('does not claim a limit when the whole result fits', () => {
    render(
      <SelectionToolbar
        mode="all-matching"
        perPageCount={50}
        matchingCount={120}
        totalMatched={120}
        onSelectAllMatching={noop}
        onClear={noop}
      />,
    );

    expect(screen.queryByText(/więcej nie można zaznaczyć/i)).toBeNull();
    expect(screen.getByText(/zaznaczone we wszystkich stronach/i)).toBeTruthy();
  });
});
