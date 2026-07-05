import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DashboardSummary, DashboardSummaryDto } from '../../use-dashboard-summary';

/**
 * DASH-06 (#2259) — the summary façade is mocked so each case pins one
 * path: degraded (null → honest "—" shells, no resurrected mock numbers)
 * or live (values straight from the endpoint DTO). formatInt/formatDelta
 * stay real (re-exported from the actual module).
 */
const state: { summary: DashboardSummaryDto | null } = { summary: null };

vi.mock('../../use-dashboard-summary', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../use-dashboard-summary')>();
  return {
    ...actual,
    useDashboardSummary: (): DashboardSummary => ({
      summary: state.summary,
      isLoading: false,
      refetch: () => {},
    }),
  };
});

import { ActionCenter } from '../ActionCenter';
import { CatalogHealthCard } from '../CatalogHealthCard';
import { KpiBand } from '../KpiBand';

const renderWithRouter = (node: ReactNode) => render(<MemoryRouter>{node}</MemoryRouter>);

const LIVE_SUMMARY: DashboardSummaryDto = {
  products: { total: 194, delta30d: 12 },
  publishReady: { count: 120, pct: 62, delta30d: null },
  avgCompleteness: { pct: 71, delta30d: -3, weeklyDeltaPoints: 2 },
  buckets: [
    { gte: 25, count: 180 },
    { gte: 50, count: 170 },
    { gte: 80, count: 120 },
    { gte: 100, count: 40 },
  ],
  channels: [
    { code: 'google', name: 'Google Shopping', avgPct: 58, readyCount: 96 },
    { code: 'allegro', name: 'Allegro', avgPct: 92, readyCount: 180 },
  ],
  openAlerts: null,
};

beforeEach(() => {
  state.summary = null;
});

describe('KpiBand', () => {
  it('renders honest "—" shells when the endpoint is degraded (no mock numbers)', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.getAllByText('—')).toHaveLength(4);
    expect(screen.queryByText('12 847')).not.toBeInTheDocument();
    expect(screen.getAllByText('brak trendu')).toHaveLength(3);
    expect(screen.getByText('24h · brak trendu')).toBeInTheDocument();
  });

  it('renders live values with real product delta and honest nulls elsewhere', () => {
    state.summary = LIVE_SUMMARY;
    renderWithRouter(<KpiBand />);
    expect(screen.getByText('194')).toBeInTheDocument();
    expect(screen.getByText('120')).toBeInTheDocument();
    expect(screen.getByText('71%')).toBeInTheDocument();
    // products delta is live (+12); avg delta is negative (−3); the
    // publish-ready delta has no snapshot horizon yet → "brak trendu".
    expect(screen.getByText('+12')).toBeInTheDocument();
    expect(screen.getByText('−3')).toBeInTheDocument();
    expect(screen.getAllByText('brak trendu')).toHaveLength(1);
    // openAlerts is null until DASH-09 → the alerts tile shows "—".
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.getByText('24h · brak trendu')).toBeInTheDocument();
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

  it('does not render any MOCK badge (operator decision)', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.queryByText('MOCK')).not.toBeInTheDocument();
  });
});

describe('CatalogHealthCard', () => {
  it('renders the live aggregate: ring, disjoint buckets, weekly badge, channels', () => {
    state.summary = LIVE_SUMMARY;
    renderWithRouter(<CatalogHealthCard />);
    expect(screen.getByText('62%')).toBeInTheDocument();
    expect(screen.getByText('120')).toBeInTheDocument();
    expect(screen.getByText(/194 SKU ≥ 80%/)).toBeInTheDocument();
    // Cumulative [25:180, 50:170, 80:120, 100:40] over 194 total →
    // disjoint [40, 80, 50, 10, 14].
    for (const count of ['40', '80', '50', '10', '14']) {
      expect(screen.getAllByText(count).length).toBeGreaterThan(0);
    }
    // weeklyDeltaPoints=2 → the badge renders.
    expect(screen.getByText(/pkt \/ tydz\./)).toBeInTheDocument();
    // Channels straight from the DTO, worst-first order preserved.
    expect(screen.getByText('Google Shopping')).toBeInTheDocument();
    expect(screen.getByText('Allegro')).toBeInTheDocument();
    expect(screen.getByText('58%')).toBeInTheDocument();
  });

  it('degrades to an honest shell: no badge, empty channels state, zero buckets', () => {
    renderWithRouter(<CatalogHealthCard />);
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.queryByText(/pkt \/ tydz\./)).not.toBeInTheDocument();
    expect(screen.getByText(/Brak kanałów z danymi kompletności/)).toBeInTheDocument();
    expect(screen.queryByText('4210')).not.toBeInTheDocument();
  });

  it('drills down from every bucket legend row and channel row', () => {
    state.summary = LIVE_SUMMARY;
    renderWithRouter(<CatalogHealthCard />);
    const bucketLinks = screen.getAllByRole('link', { name: /Pokaż produkty o kompletności/ });
    expect(bucketLinks).toHaveLength(5);
    expect(bucketLinks[1]).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=between'),
    );
    const channelLinks = screen.getAllByRole('link', { name: /Pokaż produkty poniżej progu/ });
    expect(channelLinks).toHaveLength(2);
    expect(channelLinks[0]).toHaveAttribute(
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
