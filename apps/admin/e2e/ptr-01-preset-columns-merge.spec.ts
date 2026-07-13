import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * PTR-01 — column views merged into smart presets. The separate "saved
 * views" rail is gone; saving a "Własny preset" now (a) can happen with no
 * filter conditions (the old UI guard is removed) and (b) snapshots the
 * current column layout into the preset payload.
 */

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('save a custom preset with no filter — the payload carries an empty query + a column snapshot', async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.goto('/products');

  // The saved-views rail is gone — its "Zapisz widok" CTA must not exist.
  await expect(page.getByRole('button', { name: /zapisz widok|save view/i })).toHaveCount(0);

  // Capture the create request. Only POST is handled; the list GET passes
  // through untouched.
  let payload: { query?: unknown; columns?: unknown } | null = null;
  await page.route('**/api/smart-filter-presets', async (route) => {
    if (route.request().method() !== 'POST') {
      return route.fallback();
    }
    payload = route.request().postDataJSON() as { query?: unknown; columns?: unknown };
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        id: '019f0000-0000-7000-8000-0000000000aa',
        slug: 'bez-filtra',
        name: { pl: 'Bez filtra', en: 'No filter' },
        icon: '📊',
        query: payload.query,
        columns: payload.columns,
        is_built_in: false,
        is_system: false,
        sort_order: 100,
        created_at: '2026-07-13T00:00:00+00:00',
        updated_at: '2026-07-13T00:00:00+00:00',
      }),
    });
  });

  // No filters applied. Clicking "Własny preset" used to toast "add a
  // condition first"; now it opens the save modal directly.
  await page.getByRole('button', { name: /własny preset/i }).click();
  await expect(page.getByText(/zapisz jako smart preset/i)).toBeVisible();

  await page.locator('#smart-preset-name-pl').fill('Bez filtra');
  await page.locator('#smart-preset-name-en').fill('No filter');
  await page.getByRole('button', { name: /^zapisz$/i }).click();

  await expect.poll(() => payload).not.toBeNull();
  // Saved with no filter → an empty AND group.
  expect((payload as { query?: unknown }).query).toEqual({ operator: 'AND', conditions: [] });
  // The column layout is snapshotted into the preset (the merged "view" role)
  // — only the columns on the list, NOT the full 200+ attribute catalogue.
  const columns = (payload as { columns?: unknown }).columns;
  expect(Array.isArray(columns)).toBe(true);
  expect((columns as unknown[]).length).toBeGreaterThan(0);
  expect((columns as unknown[]).length).toBeLessThan(50);
});
