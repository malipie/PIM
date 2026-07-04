import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DASH-01 (#2249) — dashboard drill-downs deep-link into the products list
 * with `filter[attr][op]=…` params; the list seeds the advanced filter
 * panel from the URL and lands in search mode with the filter applied.
 *
 * Language pinned to `pl` (addInitScript) so aria-labels are deterministic
 * — same pattern as view-13-dashboard-command-center.spec.ts.
 */

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('deep-link with a completeness filter seeds the panel and applies the search', async ({
  page,
}) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  // Register the listener before navigation (lesson: a response delivered
  // before the listener attaches is silently missed).
  const searchResponse = page.waitForResponse(
    (res) => res.url().includes('/api/search/products') && res.request().method() === 'GET',
    { timeout: 20_000 },
  );

  await page.goto('/products?filter[completeness_pct][op]=gte&filter[completeness_pct][value]=80');

  // The seeded filter switches the list into search mode and the search
  // request carries the base64 filter blob derived from the URL condition.
  const response = await searchResponse;
  expect(response.status()).toBe(200);
  const blob = new URL(response.url()).searchParams.get('q');
  expect(blob).not.toBeNull();
  expect(JSON.parse(Buffer.from(String(blob), 'base64').toString('utf8'))).toEqual({
    attr: 'completeness_pct',
    op: '>=',
    value: '80',
  });

  // The advanced filter panel opens pre-populated: operator select shows
  // the decoded op and the value input carries the threshold.
  const panel = page.getByRole('region', { name: 'Filtr zaawansowany' });
  await expect(panel).toBeVisible();
  await expect(panel.getByLabel('Operator')).toHaveValue('>=');
  await expect(panel.getByRole('textbox').last()).toHaveValue('80');
});

test('pagination-only params do not fake a filter (browse mode unchanged)', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);
  await page.goto('/products?page=1&pageSize=50');

  await expect(page.getByRole('region', { name: 'Filtr zaawansowany' })).toHaveCount(0);
});
