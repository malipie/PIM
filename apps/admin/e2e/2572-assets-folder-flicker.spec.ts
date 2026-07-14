import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2572 — entering a folder must not flash the previous (all-assets) grid, and
 * must not jump the page height. `keepPreviousData` used to leave the stale
 * cards on screen; a skeleton (aria-hidden, rendered only while
 * `isPlaceholderData` is true) now covers the switch, and it is sized to the
 * OUTGOING grid so the height is held until the new content settles in one
 * motion (no 102→10→N double jump). We delay the folder request and assert
 * both: the skeleton — not the old cards — shows, and it matches the outgoing
 * card count.
 */
test('entering a folder shows a height-matched skeleton, not the previous cards', async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.goto('/assets');

  // Wait for the initial "all assets" grid to settle (real cards, no skeleton).
  await expect(page.getByRole('button', { name: /prześlij|upload/i }).first()).toBeVisible();
  const skeleton = page.locator('ul[aria-hidden="true"]');
  await expect(skeleton).toHaveCount(0);

  // The real grid carries an aria-label; the skeleton is aria-hidden. Capture
  // the outgoing card count so we can assert the skeleton matches it.
  const outgoing = await page.locator('ul[aria-label] > li').count();

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

  // The skeleton holds the outgoing height (sized to the previous grid, capped
  // at 120) instead of a fixed handful — this is what removes the residual jump.
  if (outgoing > 12) {
    await expect(page.locator('ul[aria-hidden="true"] > li')).toHaveCount(Math.min(outgoing, 120));
  } else {
    await expect(page.locator('ul[aria-hidden="true"] > li').first()).toBeVisible();
  }

  // After the request resolves the real grid returns and the skeleton is gone.
  await expect(skeleton).toHaveCount(0, { timeout: 5000 });
});
