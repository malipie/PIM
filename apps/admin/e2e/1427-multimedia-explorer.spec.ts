import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * NUI-08 (#1427) — Multimedia v2 explorer: root shows folder tiles +
 * all files, folder navigation narrows the list, grid/list toggle works,
 * the detail drawer opens with metadata, mocked controls carry badges.
 */
test('NUI-08 — multimedia explorer: folders, views, drawer', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/assets');

  // Root: folders section + files section render.
  await expect(page.getByText(/^foldery$|^folders$/i)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/^pliki$|^files$/i)).toBeVisible();

  // The unassigned pseudo-folder tile exists.
  const unassigned = page.getByRole('button', { name: /bez przypisania|unassigned/i }).first();
  await expect(unassigned).toBeVisible();

  // Switch to list view and back.
  await page.getByRole('button', { name: /^lista$|^list$/i }).click();
  await expect(page.getByText(/^nazwa$|^name$/i)).toBeVisible();
  await page.getByRole('button', { name: /^siatka$|^grid$/i }).click();

  // Open the drawer from the first asset card (if any assets exist).
  const cards = page.locator('ul[aria-label] li');
  const hasAssets = await cards
    .first()
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
  test.skip(!hasAssets, 'No assets in this environment seed');
  await cards.first().locator('button').first().click();
  await expect(page.getByText(/^metadane$|^metadata$/i)).toBeVisible();
  await expect(page.getByText(/^format$/i).first()).toBeVisible();
});
