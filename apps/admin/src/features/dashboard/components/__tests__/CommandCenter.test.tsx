import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ActionCenter } from '../ActionCenter';
import { CatalogHealthCard } from '../CatalogHealthCard';
import { KpiBand } from '../KpiBand';

/**
 * VIEW-13 (#2143) — command-center dashboard mock. Locks in the pinned KPI
 * values, the health card structure and the action-center counters, plus the
 * operator decision that no widget renders a MOCK badge.
 */
describe('KpiBand', () => {
  it('renders the four pinned tiles with exact mock values', () => {
    render(<KpiBand />);
    expect(screen.getByText('12 847')).toBeInTheDocument();
    expect(screen.getByText('10 984')).toBeInTheDocument();
    expect(screen.getByText('87%')).toBeInTheDocument();
    expect(screen.getByText('Gotowe do publikacji')).toBeInTheDocument();
  });

  it('renders "brak trendu" on the alerts tile instead of a fake delta', () => {
    render(<KpiBand />);
    expect(screen.getByText('24h · brak trendu')).toBeInTheDocument();
    // Exactly three tiles carry a delta arrow (products, ready, completeness).
    expect(screen.getAllByText(/^\+(184|312|3 pkt)$/)).toHaveLength(3);
  });

  it('does not render any MOCK badge (operator decision)', () => {
    render(<KpiBand />);
    expect(screen.queryByText('MOCK')).not.toBeInTheDocument();
  });
});

describe('CatalogHealthCard', () => {
  it('renders ring value, bucket legend and worst-first channels', () => {
    render(<CatalogHealthCard />);
    expect(screen.getByText('85%')).toBeInTheDocument();
    expect(screen.getByText('gotowe do publikacji')).toBeInTheDocument();
    expect(screen.getByText('4210')).toBeInTheDocument();
    expect(screen.getByText('80–99%')).toBeInTheDocument();
    expect(screen.getByText('Kompletność wg kanału')).toBeInTheDocument();
    const channels = ['Google Shopping', 'BaseLinker', 'Shopify', 'Comarch ERP XL'];
    for (const channel of channels) {
      expect(screen.getByText(channel)).toBeInTheDocument();
    }
    expect(screen.queryByText('MOCK')).not.toBeInTheDocument();
  });
});

describe('ActionCenter', () => {
  it('renders counters and all five mock items with CTAs', () => {
    render(<ActionCenter />);
    expect(screen.getByText('Centrum akcji')).toBeInTheDocument();
    expect(screen.getByText('5 spraw')).toBeInTheDocument();
    expect(screen.getByText('2 krytyczne')).toBeInTheDocument();
    expect(screen.getByText('3 ostrzeżenia')).toBeInTheDocument();
    expect(screen.getAllByText('oznacz jako przeczytane')).toHaveLength(5);
    expect(screen.getAllByText('Krytyczny')).toHaveLength(2);
    expect(screen.getAllByText('Ostrzeżenie')).toHaveLength(3);
    expect(screen.getByRole('button', { name: 'Pobierz raport błędów' })).toBeInTheDocument();
    expect(screen.queryByText('MOCK')).not.toBeInTheDocument();
  });
});
