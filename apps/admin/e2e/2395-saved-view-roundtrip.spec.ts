import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * GRID-P4-02 (#2395) — the grid config round-trips through a saved view.
 * Hide a column + set compact + save; the view tab appears immediately
 * (reloadToken refetch); reset dirties the state; restoring the view
 * brings the columns and density back. Browser-only (no page.request)
 * so it runs on CI without the pim.localhost DNS caveat.
 */

test('saves and restores column visibility + density through a saved view', async ({ page }) => {
  test.setTimeout(90_000);
  await loginAsAdmin(page);
  await page.goto('/products');
  await page.getByTestId('grid-header-code').waitFor();

  // Hide "completeness" and switch to compact density.
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-row-completeness').getByRole('checkbox').click();
  await page.keyboard.press('Escape');
  await page.getByTestId('density-compact').click();
  await expect(page.getByTestId('grid-header-completeness')).toHaveCount(0);

  // Save the view — its config carries columns + density.
  const viewName = `E2E-view-${Date.now().toString(36)}`;
  const post = page.waitForResponse(
    (r) => r.url().includes('/api/saved-views') && r.request().method() === 'POST',
  );
  await page.getByRole('button', { name: /zapisz widok|save view/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel(/name/i).fill(viewName);
  await dialog.getByRole('button', { name: /zapisz widok|save view/i }).click();
  const postResp = await post;
  expect(postResp.status()).toBe(201);
  const body = postResp.request().postData() ?? '';
  expect(body).toContain('"columns"');
  expect(body).toContain('"density":"compact"');

  // The tab appears without a reload (reloadToken refetch).
  const tab = page.getByRole('tab', { name: viewName });
  await expect(tab).toBeVisible();

  // Dirty the state: back to defaults.
  await page.getByTestId('density-normal').click();
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-reset').click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('grid-header-completeness')).toBeVisible();

  // Restore the view → columns + density come back.
  await tab.click();
  await expect(page.getByTestId('grid-header-completeness')).toHaveCount(0);
  await expect(page.getByTestId('density-compact')).toHaveAttribute('aria-selected', 'true');
});
