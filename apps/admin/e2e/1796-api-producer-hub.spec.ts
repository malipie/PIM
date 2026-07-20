import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * APIC-P4-06 (#1796) — the producer hub with three deep-linkable tabs. Profiles,
 * keys, webhooks + delivery history are mocked, so the test is deterministic and
 * offline: switch tabs and assert each section's content.
 */
test('APIC-P4-06 — producer hub: profiles / keys / webhooks tabs', async ({ page }) => {
  await loginAsAdmin(page);

  await page.route('**/api/api_profiles**', (r: Route) =>
    r.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [
          {
            id: 'prof-1',
            code: 'storefront',
            name: 'Storefront feed',
            status: 'active',
            outputFormat: 'json_ld',
            objectTypeIds: ['ot-1'],
            includedAttributes: ['name', 'sku'],
            webhookUrl: 'https://hooks.partner.test/in',
            webhookEvents: ['object.created.product', 'object.published'],
          },
        ],
        totalItems: 1,
      }),
    }),
  );
  await page.route('**/api/api_keys**', (r: Route) =>
    r.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [
          {
            id: 'key-1',
            keyPrefix: 'pim_live_AB12',
            name: 'Partner X key',
            scopes: ['catalog:read'],
            expiresAt: null,
            revokedAt: null,
            lastUsedAt: '2026-06-20T10:00:00+00:00',
          },
        ],
        totalItems: 1,
      }),
    }),
  );
  await page.route('**/api/webhook_deliveries**', (r: Route) =>
    r.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [
          {
            id: 'wd-1',
            profileId: 'prof-1',
            eventType: 'object.published',
            status: 'delivered',
            attempts: 1,
            createdAt: '2026-06-21T10:00:00+00:00',
          },
        ],
        totalItems: 1,
      }),
    }),
  );

  // #2576 — the producer hub moved off the configurator landing (which now
  // redirects to the Połączenia tab) to its own /producer path.
  await page.goto('/integrations/api-configurator/producer');

  // Profiles tab (default): the profile card (the in-page "Moje API" header
  // was removed in #2671).
  await expect(page.getByText('Storefront feed', { exact: true })).toBeVisible();

  // Keys tab.
  await page.getByRole('tab', { name: /^klucze$|^keys$/i }).click();
  await expect(page.getByText('Partner X key', { exact: true })).toBeVisible();
  await expect(page.getByText(/catalog:read/)).toBeVisible();

  // Webhooks tab: the configured profile + its events.
  await page.getByRole('tab', { name: /webhooki|webhooks/i }).click();
  await expect(page.getByText('https://hooks.partner.test/in', { exact: true })).toBeVisible();
  await expect(page.getByText('object.published', { exact: true }).first()).toBeVisible();

  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);
});
