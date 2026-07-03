import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ProvenanceBadge } from '@/components/provenance-badge';

/**
 * AGENT-P6-05 (#1978) — the agent provenance badge is a first-class
 * variant: full-contrast tone (no stale "Faza 2" desaturation) and a
 * tooltip carrying "set by the agent" + the run id.
 */
describe('ProvenanceBadge agent variant', () => {
  it('renders the agent label with the run id tooltip', () => {
    render(<ProvenanceBadge provenance="agent" source="0197c3b0-0000-7000-8000-00000000cafe" />);

    const badge = screen.getByTitle(/Ustawione przez agenta|Set by the agent/);
    expect(badge).toBeInTheDocument();
    expect(badge.getAttribute('title')).toContain('0197c3b0-0000-7000-8000-00000000cafe');
    expect(badge.className).not.toContain('opacity-70');
  });

  it('keeps the plain source tooltip for other provenances', () => {
    render(<ProvenanceBadge provenance="import" source="products.csv" />);

    const badge = screen.getByTitle(/products\.csv/);
    expect(badge.getAttribute('title')).toContain('products.csv');
  });
});
