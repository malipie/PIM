import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2277 — the modeling attributes list must surface a just-created
 * attribute. Attribute ids are UUIDv7 (time-ordered), so the list sorts
 * by id descending: newest first. Stubbed so the order assertion is
 * deterministic regardless of the seed data.
 */

// Ascending id order in the API response; the UI must flip it to
// newest-first (attr_new → attr_mid → attr_old).
const ATTRS = [
  { id: '019f0000-0000-7000-8000-000000000001', code: 'attr_old', type: 'text' },
  { id: '019f5000-0000-7000-8000-000000000002', code: 'attr_mid', type: 'text' },
  { id: '019f9999-0000-7000-8000-000000000003', code: 'attr_new', type: 'text' },
];

async function stubAttributes(page: Page) {
  await page.route(
    (url) => url.pathname === '/api/attributes',
    (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify({
          '@context': '/api/contexts/Attribute',
          '@id': '/api/attributes',
          '@type': 'Collection',
          totalItems: ATTRS.length,
          member: ATTRS.map((a) => ({
            '@id': `/api/attributes/${a.id}`,
            '@type': 'Attribute',
            id: a.id,
            code: a.code,
            label: { en: a.code },
            type: a.type,
          })),
        }),
      }),
  );
  // Per-row usage + options fan-out and the group combobox: empty stubs
  // to keep the render deterministic and quiet.
  await page.route(
    (url) => /\/api\/attributes\/[^/]+\/usage$/.test(url.pathname),
    (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{}' }),
  );
  await page.route(
    (url) => /\/api\/attributes\/[^/]+\/options$/.test(url.pathname),
    (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: '{"member":[]}' }),
  );
  await page.route(
    (url) => url.pathname === '/api/attribute_groups',
    (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify({ totalItems: 0, member: [] }),
      }),
  );
}

test('attributes list renders newest first so a fresh attribute is at the top', async ({
  page,
}) => {
  await page.addInitScript(() => window.localStorage.setItem('i18nextLng', 'pl'));
  await stubAttributes(page);
  await loginAsAdmin(page);
  await page.goto('/modeling/attributes');

  const rows = page.locator('a[href*="/modeling/attributes/019f"]');
  await expect(rows).toHaveCount(3);

  // Newest-first: attr_new before attr_mid before attr_old.
  await expect(rows.nth(0)).toContainText('attr_new');
  await expect(rows.nth(1)).toContainText('attr_mid');
  await expect(rows.nth(2)).toContainText('attr_old');
});
