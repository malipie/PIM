import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-01 (#1930) — the feeds hub under the Konfigurator shell: fourth
 * pill tab, KPI strip fed by /api/feed-runs/kpi + /api/feeds/pull-stats,
 * feed cards with coverage, search + status filter, the NewFeedCard CTA and
 * the ad-hoc XML note. All feed endpoints are mocked — deterministic and
 * offline.
 */

const FEEDS = [
  {
    id: '019f0000-0000-7000-8000-000000000001',
    code: 'google_pl',
    name: 'Google Shopping — Polska',
    template_kind: 'google_shopping',
    status: 'active',
    locale: 'pl',
    currency: 'PLN',
    channel_id: null,
    publication_channel: 'google',
    schedule_cron: '0 3 * * *',
    descriptor: { item: { slots: [{ slot: 'g:id' }, { slot: 'g:title' }] } },
    field_mappings: [{ slot: 'g:id' }],
    cached_item_count: 12408,
    cached_at: '2026-07-01T04:00:00+00:00',
    last_pulled_at: '2026-07-01T21:00:00+00:00',
    has_token: true,
  },
  {
    id: '019f0000-0000-7000-8000-000000000002',
    code: 'ceneo_elektro',
    name: 'Ceneo — Elektronika',
    template_kind: 'ceneo',
    status: 'paused',
    locale: 'pl',
    currency: 'PLN',
    channel_id: null,
    publication_channel: null,
    schedule_cron: null,
    descriptor: { item: { slots: [{ slot: 'cat' }] } },
    field_mappings: [],
    cached_item_count: null,
    cached_at: null,
    last_pulled_at: null,
    has_token: false,
  },
];

test('XMLF-P5-01 — feeds hub: tab, KPI, cards, filtering, a11y', async ({ page }) => {
  await loginAsAdmin(page);

  await page.route('**/api/feeds', (route) => route.fulfill({ json: { items: FEEDS } }));
  await page.route('**/api/feed-runs/kpi', (route) =>
    route.fulfill({
      json: {
        regenerations_24h: 6,
        skipped_24h: 112,
        errors_24h: 0,
        last_error: null,
        items_syndicated: 12408,
        last_regenerated_at: '2026-07-01T04:00:00+00:00',
        feeds: { active: 1, paused: 1, error: 0 },
      },
    }),
  );
  await page.route('**/api/feeds/pull-stats', (route) =>
    route.fulfill({
      json: {
        pulls_24h: 1284,
        spark: [{ bucket: '2026-07-01T20:00:00+00:00', count: 640 }],
      },
    }),
  );

  await page.goto('/integrations/api-configurator/feeds');

  // XMLF-FUP-01 (#2028): feeds live under their own sidebar entry
  // ("Konfigurator XML"), highlighted for the /feeds prefix — and the
  // Konfigurator API shell no longer renders its pill tabs here.
  const sidebarEntry = page.getByRole('link', { name: /Konfigurator XML|XML Configurator/ });
  await expect(sidebarEntry).toBeVisible();
  await expect(sidebarEntry).toHaveClass(/bg-zinc-100/);
  await expect(
    page.getByLabel(/API Configurator|Konfigurator API/).getByRole('tab', { name: /Feedy|Feeds/ }),
  ).toHaveCount(0);

  // KPI strip renders the merged aggregates. pl-PL grouping starts at five
  // digits (CLDR minimumGroupingDigits=2), so 1284 renders bare and 12408 gets
  // an NBSP separator; an optional whitespace matches both.
  await expect(page.getByText(/1\s?284/)).toBeVisible();
  await expect(page.getByText(/12\s?408/).first()).toBeVisible();

  // Cards render with template badge + coverage.
  await expect(page.getByText('Google Shopping — Polska')).toBeVisible();
  await expect(page.getByText('Ceneo — Elektronika')).toBeVisible();
  await expect(page.getByText('1/2')).toBeVisible();

  // Status filter narrows the grid.
  await page.getByRole('button', { name: /wstrzymane|^paused$/i }).click();
  await expect(page.getByText('Ceneo — Elektronika')).toBeVisible();
  await expect(page.getByText('Google Shopping — Polska')).not.toBeVisible();
  await page.getByRole('button', { name: /wszystkie|^all$/i }).click();

  // Search combines with the filter.
  await page.getByRole('textbox', { name: /Szukaj feedów|Search feeds/ }).fill('google');
  await expect(page.getByText('Google Shopping — Polska')).toBeVisible();
  await expect(page.getByText('Ceneo — Elektronika')).not.toBeVisible();
  await page.getByRole('textbox', { name: /Szukaj feedów|Search feeds/ }).fill('');

  // NewFeedCard CTA routes to the wizard placeholder.
  await page.getByRole('button', { name: /Nowy feed|New feed/ }).click();
  await expect(page).toHaveURL(/\/integrations\/api-configurator\/feeds\/new$/);
  // The target is the full wizard since P5-02 — return via its back control.
  await expect(page.getByRole('heading', { name: /Nowy feed|New feed/ })).toBeVisible();
  await page.getByRole('button', { name: /Wróć do feedów|Back to feeds/ }).click();
  await expect(page).toHaveURL(/\/integrations\/api-configurator\/feeds$/);

  // a11y — no serious/critical violations on the hub.
  // `.exclude('.bg-cta')` — the orange topbar CTA (#2671) is the sanctioned
  // design accent excluded across a11y specs (same as nui-13 / exr-16).
  const axe = await new AxeBuilder({ page }).exclude('.bg-cta').analyze();
  const blocking = axe.violations.filter(
    (violation) => violation.impact === 'serious' || violation.impact === 'critical',
  );
  expect(blocking).toEqual([]);
});
