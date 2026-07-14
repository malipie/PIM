import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2572 — entering a folder must not flash the previous (all-assets) grid.
 * With `keepPreviousData` the stale cards used to stay on screen until the
 * folder's set arrived; now a skeleton (aria-hidden placeholder, rendered
 * only while `isPlaceholderData` is true) covers the switch. We delay the
 * folder request and assert the skeleton — not the old cards — is shown.
 */
test('entering a folder shows a skeleton, not the previous folder cards', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/assets');

  // Wait for the initial "all assets" grid to settle (real cards, no skeleton).
  await expect(page.getByRole('button', { name: /prześlij|upload/i }).first()).toBeVisible();
  const skeleton = page.locator('ul[aria-hidden="true"]');
  await expect(skeleton).toHaveCount(0);

  // Hold the next assets request so the switch stays visible long enough.
  let armed = false;
  await page.route('**/api/assets?**', async (route) => {
    if (armed) {
      await new Promise((r) => setTimeout(r, 1200));
    }
    await route.continue();
  });
  armed = true;

  // Double-click the first folder tile (Explorer semantics, #2320). The
  // "Bez przypisania" tile is always present, so this is data-independent.
  const firstFolder = page.getByTestId('folder-tile').first();
  await expect(firstFolder).toBeVisible();
  await firstFolder.dblclick();

  // While the folder loads the skeleton is shown (proves stale cards are hidden).
  await expect(skeleton).toBeVisible();

  // After the request resolves the real grid returns and the skeleton is gone.
  await expect(skeleton).toHaveCount(0, { timeout: 5000 });
});
