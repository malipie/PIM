import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DashboardSummary } from '../../use-dashboard-summary';

/**
 * DASH-02 (#2251) — the summary façade is mocked so each case pins one
 * path: degraded (null → approved mock values per widget) or live
 * (values from the aggregate). formatInt stays real (re-exported from
 * the actual module).
 */
const summaryState: DashboardSummary = {
  productsTotal: null,
  completeness: null,
  isLoading: false,
};

vi.mock('../../use-dashboard-summary', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../use-dashboard-summary')>();
  return {
    ...actual,
    useDashboardSummary: (): DashboardSummary => ({ ...summaryState }),
  };
});

import { ActionCenter } from '../ActionCenter';
import { CatalogHealthCard } from '../CatalogHealthCard';
import { KpiBand } from '../KpiBand';

const renderWithRouter = (node: ReactNode) => render(<MemoryRouter>{node}</MemoryRouter>);

const LIVE_COMPLETENESS = {
  total: 194,
  publishReady: 120,
  publishReadyPct: 62,
  buckets: [
    { gte: 25, count: 180 },
    { gte: 50, count: 170 },
    { gte: 80, count: 120 },
    { gte: 100, count: 40 },
  ],
};

beforeEach(() => {
  summaryState.productsTotal = null;
  summaryState.completeness = null;
  summaryState.isLoading = false;
});

describe('KpiBand', () => {
  it('degrades to the approved mock values when the aggregates are unavailable', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.getByText('12 847')).toBeInTheDocument();
    expect(screen.getByText('10 984')).toBeInTheDocument();
    expect(screen.getByText('87%')).toBeInTheDocument();
    expect(screen.getByText('Gotowe do publikacji')).toBeInTheDocument();
  });

  it('renders live counts with "brak trendu" instead of the mock deltas', () => {
    summaryState.productsTotal = 194;
    summaryState.completeness = LIVE_COMPLETENESS;
    renderWithRouter(<KpiBand />);
    expect(screen.getByText('194')).toBeInTheDocument();
    expect(screen.getByText('120')).toBeInTheDocument();
    // Live tiles never fabricate a trend (NUI-02); only the still-mock
    // avg-completeness tile keeps its approved delta.
    expect(screen.getAllByText('brak trendu')).toHaveLength(2);
    expect(screen.queryAllByText(/^\+(184|312)$/)).toHaveLength(0);
    expect(screen.getByText('+3 pkt')).toBeInTheDocument();
  });

  it('renders every tile as a drill-down link (brief §5-C)', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.getByRole('link', { name: /łącznie w katalogu/ })).toHaveAttribute(
      'href',
      '/products',
    );
    expect(screen.getByRole('link', { name: /Gotowe do publikacji/ })).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=gte'),
    );
    expect(screen.getByRole('link', { name: /Średnia kompletność/ })).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=lt'),
    );
    expect(screen.getByRole('link', { name: /Otwarte alerty/ })).toHaveAttribute(
      'href',
      '#action-center',
    );
  });

  it('renders "24h · brak trendu" on the alerts tile instead of a fake delta', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.getByText('24h · brak trendu')).toBeInTheDocument();
  });

  it('does not render any MOCK badge (operator decision)', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.queryByText('MOCK')).not.toBeInTheDocument();
  });
});

describe('CatalogHealthCard', () => {
  it('degrades to mock ring, legend and channels when the aggregate is unavailable', () => {
    renderWithRouter(<CatalogHealthCard />);
    expect(screen.getByText('85%')).toBeInTheDocument();
    expect(screen.getByText('gotowe do publikacji')).toBeInTheDocument();
    expect(screen.getByText('4210')).toBeInTheDocument();
    expect(screen.getByText('80–99%')).toBeInTheDocument();
    // Mock path carries the approved weekly-trend badge.
    expect(screen.getByText(/pkt \/ tydz\./)).toBeInTheDocument();
    expect(screen.getByText('Kompletność wg kanału')).toBeInTheDocument();
    const channels = ['Google Shopping', 'BaseLinker', 'Shopify', 'Comarch ERP XL'];
    for (const channel of channels) {
      expect(screen.getByText(channel)).toBeInTheDocument();
    }
    expect(screen.queryByText('MOCK')).not.toBeInTheDocument();
  });

  it('renders the live aggregate: disjoint buckets, ready count, no fabricated trend badge', () => {
    summaryState.completeness = LIVE_COMPLETENESS;
    renderWithRouter(<CatalogHealthCard />);
    expect(screen.getByText('62%')).toBeInTheDocument();
    expect(screen.getByText('120')).toBeInTheDocument();
    expect(screen.getByText(/194 SKU ≥ 80%/)).toBeInTheDocument();
    // Cumulative [25:180, 50:170, 80:120, 100:40] over 194 total →
    // disjoint [40, 80, 50, 10, 14].
    const legend = ['40', '80', '50', '10', '14'];
    for (const count of legend) {
      expect(screen.getAllByText(count).length).toBeGreaterThan(0);
    }
    expect(screen.queryByText(/pkt \/ tydz\./)).not.toBeInTheDocument();
  });

  it('drills down from every bucket legend row to the filtered products list', () => {
    renderWithRouter(<CatalogHealthCard />);
    const bucketLinks = screen.getAllByRole('link', { name: /Pokaż produkty o kompletności/ });
    expect(bucketLinks).toHaveLength(5);
    expect(bucketLinks[0]).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=gte'),
    );
    expect(bucketLinks[1]).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=between'),
    );
    expect(bucketLinks[4]).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=lt'),
    );
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

  it('carries the anchor id the KPI alerts tile jumps to', () => {
    const { container } = render(<ActionCenter />);
    expect(container.querySelector('section#action-center')).not.toBeNull();
  });
});
