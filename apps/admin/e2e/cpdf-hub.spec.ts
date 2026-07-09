import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CPDF-P5-03 — the catalogs hub under the /catalogs-pdf shell: KPI strip fed by
 * /api/catalog-runs/kpi, catalog cards with their cache state, and the Pobierz /
 * Regeneruj / Udostępnij / Usuń actions. All catalog endpoints are mocked so the
 * spec is deterministic and offline.
 */

const CATALOGS = [
  {
    id: '019f0000-0000-7000-8000-0000000000a1',
    code: 'spring_2026',
    name: 'Katalog wiosna 2026',
    template_kind: 'sheet',
    status: 'active',
    object_type_id: '019f0000-0000-7000-8000-0000000000ff',
    branding: {},
    field_mappings: [],
    filter: null,
    channel_id: null,
    publication_channel: null,
    locale: 'pl',
    renderer: 'auto',
    cached_file_size: 204800,
    cached_page_count: 48,
    cached_at: '2026-07-01T04:00:00+00:00',
    has_token: true,
    created_at: '2026-06-01T00:00:00+00:00',
    updated_at: '2026-07-01T04:00:00+00:00',
  },
  {
    id: '019f0000-0000-7000-8000-0000000000a2',
    code: 'pricelist_b2b',
    name: 'Cennik B2B',
    template_kind: 'pricelist',
    status: 'paused',
    object_type_id: '019f0000-0000-7000-8000-0000000000ff',
    branding: {},
    field_mappings: [],
    filter: null,
    channel_id: null,
    publication_channel: null,
    locale: null,
    renderer: 'auto',
    cached_file_size: null,
    cached_page_count: null,
    cached_at: null,
    has_token: false,
    created_at: '2026-06-10T00:00:00+00:00',
    updated_at: '2026-06-10T00:00:00+00:00',
  },
];

const KPI = {
  regenerations_24h: 6,
  items_24h: 12408,
  errors_24h: 0,
  pages_published: 48,
  last_regenerated_at: '2026-07-01T04:00:00+00:00',
  catalogs: { active: 1, paused: 1, error: 0 },
  last_error: null,
};

test('CPDF-P5-03 — catalogs hub: KPI, cards, actions, a11y', async ({ page }) => {
  await loginAsAdmin(page);

  await page.route('**/api/catalogs', (route) => {
    if (route.request().method() !== 'GET') return route.continue();
    return route.fulfill({ json: { items: CATALOGS } });
  });
  await page.route('**/api/catalog-runs/kpi', (route) => route.fulfill({ json: KPI }));

  let generateCalled = false;
  let tokenMintCalled = false;

  await page.route('**/api/catalogs/*/generate', (route) => {
    generateCalled = true;
    return route.fulfill({ status: 202, json: { run: { id: 'r1', status: 'pending' } } });
  });
  await page.route('**/api/catalogs/*/token', (route) => {
    tokenMintCalled = true;
    return route.fulfill({
      status: 201,
      json: { token: 't', url: '/api/catalogs/pull/x/t.pdf' },
    });
  });
  await page.route('**/api/catalogs/*', (route) => {
    if (route.request().method() === 'DELETE') {
      return route.fulfill({ status: 204, body: '' });
    }
    return route.continue();
  });

  await page.goto('/catalogs-pdf');

  const main = page.getByRole('main');

  // KPI strip renders the merged aggregates. pl-PL grouping starts at five
  // digits (CLDR minimumGroupingDigits=2), so 12408 gets an NBSP separator;
  // an optional whitespace matches both.
  await expect(main.getByText(/12\s?408/)).toBeVisible();
  await expect(main.getByText('Regeneracje 24h')).toBeVisible();

  // Both catalog cards render.
  await expect(page.getByRole('heading', { name: /Katalogi$/ })).toBeVisible();
  await expect(page.getByText('Katalog wiosna 2026')).toBeVisible();
  await expect(page.getByText('Cennik B2B')).toBeVisible();

  const firstCard = page
    .getByText('Katalog wiosna 2026')
    .locator('xpath=ancestor::div[contains(@class,"rounded-3xl")][1]');

  // Regeneruj fires the generate call.
  await firstCard.getByRole('button', { name: /Regeneruj/ }).click();
  await expect.poll(() => generateCalled).toBe(true);

  // Udostępnij mints a token and surfaces the "Skopiowano" state.
  await firstCard.getByRole('button', { name: /^Udostępnij$/ }).click();
  await expect.poll(() => tokenMintCalled).toBe(true);
  await expect(firstCard.getByText('Skopiowano')).toBeVisible();

  // a11y — no serious/critical violations on the hub. Kill CSS
  // transitions/animations so the scan does not read mid-animation colours.
  await page.addStyleTag({
    content: '*,*::before,*::after{transition:none!important;animation:none!important}',
  });
  await page.waitForTimeout(150);
  const axe = await new AxeBuilder({ page }).include('main').analyze();
  const blocking = axe.violations.filter(
    (violation) => violation.impact === 'serious' || violation.impact === 'critical',
  );
  expect(blocking).toEqual([]);
});
