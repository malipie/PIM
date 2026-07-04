import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #1887 — connector actions must give feedback. Clicking "Test" on the
 * connection detail now raises a toast with the probe result (previously the
 * action fired silently and looked dead). Fully mocked → deterministic/offline.
 */

const CONNECTION = {
  '@id': '/api/connections/conn-1',
  '@type': 'Connection',
  id: 'conn-1',
  code: 'idosell',
  name: 'IdoSell EU',
  baseUrl: 'https://estetino.pl/api/admin/v5',
  authType: 'api_key',
  rateLimitHint: 600,
  status: 'active',
  lastHealthCheckAt: null,
  createdAt: '2026-06-30T10:00:00+00:00',
  updatedAt: '2026-06-30T10:00:00+00:00',
};

test('APIC #1887 — Test action shows a result toast', async ({ page }) => {
  await loginAsAdmin(page);

  // Playwright checks the LAST-registered route first, so the general
  // connection route goes first and the specific /test route last (it must win
  // for the POST probe).
  await page.route('**/api/connections/conn-1**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify(CONNECTION),
    }),
  );
  await page.route('**/api/sync_bindings**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ '@type': 'Collection', member: [], totalItems: 0 }),
    }),
  );
  await page.route('**/api/connections/conn-1/test', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        http_status: 200,
        status: 'active',
        checked_at: '2026-06-30T12:00:00+00:00',
      }),
    }),
  );

  await page.goto('/integrations/api-configurator/connections/conn-1');

  const testButton = page.getByRole('button', { name: /^(Test|Testuj)$/ });
  await expect(testButton).toBeVisible();

  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);

  await testButton.click();

  // The probe result surfaces as a toast (the fix — no longer silent).
  await expect(page.getByText(/(Connection works|Połączenie działa) \(HTTP 200\)/i)).toBeVisible();
});
