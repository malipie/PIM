import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * APIC fix (#1883) — connection cards on the consumer hub are clickable
 * (stretched link → detail) and expose a delete action behind a confirm dialog.
 * All API is mocked, so the test is deterministic and offline (login excepted).
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

function collection(members: unknown[]): string {
  return JSON.stringify({
    '@context': '/api/contexts/Connection',
    '@id': '/api/connections',
    '@type': 'Collection',
    member: members,
    totalItems: members.length,
  });
}

test('APIC #1883 — connection card links to detail + delete with confirm', async ({ page }) => {
  await loginAsAdmin(page);

  let deleted = false;
  const deleteRequests: string[] = [];

  await page.route('**/api/connections**', (route: Route) => {
    const url = route.request().url();
    const method = route.request().method();
    if (method === 'DELETE') {
      deleted = true;
      deleteRequests.push(url);
      return route.fulfill({ status: 204, body: '' });
    }
    if (/\/api\/connections\/conn-1(?:[/?]|$)/.test(url)) {
      return route.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify(CONNECTION),
      });
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: collection(deleted ? [] : [CONNECTION]),
    });
  });
  // Detail-page side request — keep the click target offline.
  await page.route('**/api/sync_bindings**', (route: Route) =>
    route.fulfill({ status: 200, contentType: 'application/ld+json', body: collection([]) }),
  );

  await page.goto('/integrations/api-configurator/connections');

  const cardLink = page.getByRole('link', {
    name: /(open connection|otwórz połączenie) IdoSell EU/i,
  });
  await expect(cardLink).toBeVisible();

  // a11y on the hub with a rendered card.
  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);

  // 1) Whole card navigates to the detail.
  await cardLink.click();
  await expect(page).toHaveURL(/\/connections\/conn-1$/);

  await page.goBack();
  await expect(page).toHaveURL(/\/connections$/);

  // 2) Delete → confirm dialog → DELETE fired → card gone.
  await page
    .getByRole('button', { name: /(delete connection|usuń połączenie) IdoSell EU/i })
    .click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: /^(Delete connection|Usuń połączenie)$/ }).click();

  await expect.poll(() => deleteRequests.length).toBeGreaterThan(0);
  expect(deleteRequests[0]).toMatch(/\/api\/connections\/conn-1$/);
  await expect(page.getByText('IdoSell EU')).toHaveCount(0);
});
