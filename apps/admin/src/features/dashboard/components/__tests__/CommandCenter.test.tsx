import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DashboardActivityDto, DashboardTopEditedDto } from '../../use-dashboard-activity';
import type { DashboardAlertsDto } from '../../use-dashboard-alerts';
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

const activityState: {
  activity: DashboardActivityDto | null;
  topEdited: DashboardTopEditedDto | null;
} = { activity: null, topEdited: null };

vi.mock('../../use-dashboard-activity', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../use-dashboard-activity')>();
  return {
    ...actual,
    useDashboardActivity: () =>
      ({ data: activityState.activity }) as unknown as ReturnType<
        typeof actual.useDashboardActivity
      >,
    useDashboardTopEdited: () =>
      ({ data: activityState.topEdited }) as unknown as ReturnType<
        typeof actual.useDashboardTopEdited
      >,
  };
});

const alertsState: { alerts: DashboardAlertsDto | null } = { alerts: null };
const ackMock = vi.fn();

/**
 * #2831 — the cards now ask what the caller may see. `useCanI` runs a
 * React Query under the hood, which this harness has no provider for, so
 * it is stubbed from a per-test permission set.
 */
const grantedPermissions = new Set<string>();

vi.mock('@/lib/identity', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/identity')>();
  return {
    ...actual,
    useCanI: (code: string) => grantedPermissions.has(code),
    // #2839 — KpiBand asks whether the tile's target is reachable; the real
    // `canAccessPath` runs against this stub identity.
    useIdentity: () => ({ identity: { permissions: grantedPermissions }, isLoading: false }),
  };
});

vi.mock('../../use-dashboard-alerts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../use-dashboard-alerts')>();
  return {
    ...actual,
    useDashboardAlerts: () =>
      ({ data: alertsState.alerts }) as unknown as ReturnType<typeof actual.useDashboardAlerts>,
    useAckAlert: () =>
      ({ mutate: ackMock, isPending: false }) as unknown as ReturnType<typeof actual.useAckAlert>,
  };
});

import { ActionCenter } from '../ActionCenter';
import { CatalogHealthCard } from '../CatalogHealthCard';
import { KpiBand } from '../KpiBand';
import { TeamActivityCard } from '../TeamActivityCard';

const renderWithRouter = (node: ReactNode, initialEntries: string[] = ['/dashboard']) =>
  render(<MemoryRouter initialEntries={initialEntries}>{node}</MemoryRouter>);

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
  activityState.activity = null;
  activityState.topEdited = null;
  alertsState.alerts = null;
  ackMock.mockClear();
  // Default to a fully entitled caller so the existing cases keep pinning
  // rendering rather than gating; the gating cases narrow this per test.
  grantedPermissions.clear();
  grantedPermissions.add('channel.read');
  grantedPermissions.add('audit.view_cross_user');
  // #2839 — the tiles link to /products, so a "fully entitled caller" has
  // to include the code that makes that target reachable.
  grantedPermissions.add('products.view');
});

describe('KpiBand', () => {
  it('renders honest "—" shells when the endpoint is degraded (no mock numbers)', () => {
    renderWithRouter(<KpiBand />);
    expect(screen.getAllByText('—')).toHaveLength(4);
    expect(screen.queryByText('12 847')).not.toBeInTheDocument();
    expect(screen.getAllByText('brak trendu')).toHaveLength(3);
    expect(screen.getByText('24h · brak trendu')).toBeInTheDocument();
  });

  it('drops the link when the role cannot reach the target (#2839)', () => {
    // A catalog-less role: the count is still legitimately its own (the
    // backend computed it under this role), but /products would 403.
    grantedPermissions.delete('products.view');
    state.summary = LIVE_SUMMARY;

    renderWithRouter(<KpiBand />);

    expect(screen.getByText('194')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Produkty/ })).not.toBeInTheDocument();
  });

  it('keeps the link for a role that may open the target', () => {
    grantedPermissions.add('products.view');
    state.summary = LIVE_SUMMARY;

    renderWithRouter(<KpiBand />);

    expect(screen.getAllByRole('link').length).toBeGreaterThan(0);
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

  it('drops the per-channel section for a role without channel.read (#2831)', () => {
    grantedPermissions.delete('channel.read');
    state.summary = LIVE_SUMMARY;

    renderWithRouter(<CatalogHealthCard />);

    // Not the "no channels configured" empty state — the section is gone,
    // because "you may not see this" and "there is nothing here" are
    // different statements.
    expect(screen.queryByText('Kompletność wg kanału')).not.toBeInTheDocument();
    expect(screen.queryByText(/Brak kanałów z danymi kompletności/)).not.toBeInTheDocument();
    expect(screen.queryByText('Google Shopping')).not.toBeInTheDocument();

    // The rest of the card still renders — the role does hold products.view.
    expect(screen.getByText('120')).toBeInTheDocument();
  });
});

describe('TeamActivityCard', () => {
  const LIVE_ACTIVITY: DashboardActivityDto = {
    range: '7d',
    series: [
      { date: '2026-07-01', added: 2, modified: 5 },
      { date: '2026-07-02', added: 0, modified: 1 },
    ],
    addedTotal: 2,
    modifiedTotal: 6,
    avgPerDay: 1,
  };

  it('degrades to "—" totals, a flat chart and the honest empty ranking', () => {
    renderWithRouter(<TeamActivityCard />);
    expect(screen.getAllByText('—')).toHaveLength(2);
    expect(screen.getByText('Brak edycji produktów w tym okresie.')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: /Wykres dziennych zmian/ })).toBeInTheDocument();
  });

  it('reads the range from the URL and marks the toggle as pressed', () => {
    activityState.activity = LIVE_ACTIVITY;
    renderWithRouter(<TeamActivityCard />, ['/dashboard?range=7d']);
    expect(screen.getByRole('button', { name: '7 dni' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('2')).toBeInTheDocument();
    expect(screen.getByText('6')).toBeInTheDocument();
    expect(screen.getByText(/średnio 1 zmian \/ dzień/)).toBeInTheDocument();
    expect(screen.getByText('6 dni temu')).toBeInTheDocument();
    expect(screen.getByText('dziś')).toBeInTheDocument();
  });

  it('persists a toggle click in the URL (aria-pressed follows)', async () => {
    const user = userEvent.setup();
    renderWithRouter(<TeamActivityCard />);
    expect(screen.getByRole('button', { name: '30 dni' })).toHaveAttribute('aria-pressed', 'true');
    await user.click(screen.getByRole('button', { name: '90 dni' }));
    expect(screen.getByRole('button', { name: '90 dni' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('89 dni temu')).toBeInTheDocument();
  });

  it('renders every most-edited row as a product-page link', () => {
    activityState.topEdited = {
      items: [
        { id: 'p-1', name: 'Czujnik', sku: 'TOP-1', completenessPct: 96, edits: 47 },
        { id: 'p-2', name: 'Zawór', sku: 'TOP-2', completenessPct: 72, edits: 34 },
      ],
    };
    renderWithRouter(<TeamActivityCard />);
    const rows = screen.getAllByRole('link', { name: /edycji/ });
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveAttribute('href', '/products/p-1');
    expect(screen.getByText('Czujnik')).toBeInTheDocument();
    expect(screen.queryByText('Brak edycji produktów w tym okresie.')).not.toBeInTheDocument();
  });
});

describe('ActionCenter', () => {
  const LIVE_ALERTS: DashboardAlertsDto = {
    total: 2,
    critical: 1,
    warnings: 1,
    allCount: 5,
    items: [
      {
        fingerprint: 'import_session:abc',
        type: 'import_session',
        severity: 'critical',
        occurredAt: '2026-07-04 12:49:33',
        params: { sourceName: 'pim-catalog.xlsx', errorCount: 132, status: 'partial' },
        context: { sessionId: 'sess-1' },
      },
      {
        fingerprint: 'completeness_drop:allegro:2026-07-05',
        type: 'completeness_drop',
        severity: 'warning',
        occurredAt: '2026-07-05',
        params: { sourceName: 'Allegro', avgPct: 76, previousPct: 82, threshold: 80 },
        context: { channelCode: 'allegro' },
      },
    ],
  };

  it('composes titles from params and maps each type to its CTA', () => {
    alertsState.alerts = LIVE_ALERTS;
    renderWithRouter(<ActionCenter />);
    expect(screen.getByText('Centrum akcji')).toBeInTheDocument();
    expect(screen.getByText('2 spraw')).toBeInTheDocument();
    expect(screen.getByText('1 krytyczne')).toBeInTheDocument();
    // Title built from structured params (BE returns fields, not strings).
    expect(
      screen.getByText(/Import „pim-catalog\.xlsx” zakończony częściowo — 132 wierszy/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Allegro: kompletność spadła do 76% — poniżej progu publikacji \(80%\)/),
    ).toBeInTheDocument();
    // Per-type CTA hrefs.
    expect(screen.getByRole('link', { name: 'Pobierz raport błędów' })).toHaveAttribute(
      'href',
      '/integrations/imports/sess-1',
    );
    expect(screen.getByRole('link', { name: 'Pokaż produkty' })).toHaveAttribute(
      'href',
      expect.stringContaining('filter[completeness_pct][op]=lt'),
    );
  });

  it('shows "Pokaż wszystkie" only when the feed is capped', () => {
    alertsState.alerts = LIVE_ALERTS; // allCount 5 > 2 rendered
    renderWithRouter(<ActionCenter />);
    expect(screen.getByRole('button', { name: /Pokaż wszystkie/ })).toBeInTheDocument();
  });

  it('acks optimistically on "oznacz jako przeczytane" click', async () => {
    const user = userEvent.setup();
    alertsState.alerts = LIVE_ALERTS;
    renderWithRouter(<ActionCenter />);
    const [firstAck] = screen.getAllByText('oznacz jako przeczytane');
    await user.click(firstAck as HTMLElement);
    expect(ackMock).toHaveBeenCalledWith('import_session:abc', expect.anything());
  });

  it('renders the positive empty state when the feed is clear', () => {
    alertsState.alerts = { total: 0, critical: 0, warnings: 0, allCount: 0, items: [] };
    renderWithRouter(<ActionCenter />);
    expect(screen.getByText('Wszystko działa ✓')).toBeInTheDocument();
    expect(screen.getByText('0 spraw')).toBeInTheDocument();
    expect(screen.queryByText(/Pokaż wszystkie/)).not.toBeInTheDocument();
  });

  it('carries the anchor id the KPI alerts tile jumps to', () => {
    const { container } = renderWithRouter(<ActionCenter />);
    expect(container.querySelector('section#action-center')).not.toBeNull();
  });
});
