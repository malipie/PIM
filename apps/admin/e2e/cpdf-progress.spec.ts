import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CPDF-P5-04 — live regeneration progress on the catalogs hub. Clicking
 * Regeneruj fires POST /api/catalogs/{id}/generate (202) and shows the live
 * "Generowanie…" affordance driven by the Mercure run stream. Simulating a real
 * SSE frame in Playwright is impractical (the EventSource opens against the hub
 * origin), so this spec asserts the mutation-pending → live-progress transition
 * and that the UI degrades gracefully with no EventSource events: the card must
 * stay usable and pass a11y. All catalog endpoints are mocked → deterministic.
 */

const CATALOGS = [
  {
    id: '019f0000-0000-7000-8000-0000000000b1',
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
];

const KPI = {
  regenerations_24h: 6,
  items_24h: 12408,
  errors_24h: 0,
  pages_published: 48,
  last_regenerated_at: '2026-07-01T04:00:00+00:00',
  catalogs: { active: 1, paused: 0, error: 0 },
  last_error: null,
};

test('CPDF-P5-04 — hub: Regeneruj shows live progress, degrades without SSE', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });

  await loginAsAdmin(page);

  await page.route('**/api/catalogs', (route) => {
    if (route.request().method() !== 'GET') return route.continue();
    return route.fulfill({ json: { items: CATALOGS } });
  });
  await page.route('**/api/catalog-runs/kpi', (route) => route.fulfill({ json: KPI }));

  let generateCalled = false;
  await page.route('**/api/catalogs/*/generate', (route) => {
    generateCalled = true;
    return route.fulfill({
      status: 202,
      json: {
        run: { id: 'run-1', status: 'pending' },
        mercure_topic: 'tenant/t/catalogs/019f0000-0000-7000-8000-0000000000b1/runs',
      },
    });
  });
  // The Mercure authorization mint + hub subscribe never emit an SSE frame in
  // this harness — the card must fall back to the REST list refresh (no crash).
  await page.route('**/api/mercure/authorization', (route) =>
    route.fulfill({ status: 204, body: '' }),
  );

  await page.goto('/catalogs-pdf');

  const card = page
    .getByText('Katalog wiosna 2026')
    .locator('xpath=ancestor::div[contains(@class,"rounded-3xl")][1]');
  await expect(card).toBeVisible();

  // Regeneruj fires the generate call and reveals the live progress line.
  await card.getByRole('button', { name: /Regeneruj/ }).click();
  await expect.poll(() => generateCalled).toBe(true);

  // Live affordance: with no SSE frame the indeterminate "Generowanie…" status
  // shows (progress_pct unknown). role=status, not raw text.
  await expect(card.getByRole('status').filter({ hasText: 'Generowanie' })).toBeVisible();

  // Graceful degradation — the card stays interactive (no crash without SSE).
  await expect(card.getByRole('button', { name: /Pobierz/ })).toBeEnabled();

  // a11y — no serious/critical violations while the progress line is live.
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
