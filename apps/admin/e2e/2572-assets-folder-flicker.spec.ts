import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2572/#2612 — entering a folder must not flash the previous (all-assets)
 * grid. `keepPreviousData` used to leave the stale cards on screen; a skeleton
 * (aria-hidden, rendered only while `isPlaceholderData` is true) covers the
 * switch. #2600 sized that skeleton to the OUTGOING grid, which made a
 * pseudo-grid shaped like the previous view's files slide to the top and
 * collapse — the flicker reported in #2612. The skeleton (and the files-header
 * count) is now sized to the TARGET folder's assetCount, so the transitional
 * layout already matches the destination. We delay the folder request and
 * assert: the skeleton — not the old cards — shows, and both the skeleton and
 * the header count match the dbl-clicked folder's element count.
 */
test('entering a folder shows a target-sized skeleton, not the previous cards', async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.goto('/assets');

  // Wait for the initial "all assets" grid to settle (real cards, no skeleton).
  await expect(page.getByRole('button', { name: /prześlij|upload/i }).first()).toBeVisible();
  const skeleton = page.locator('ul[aria-hidden="true"]');
  const realGrid = page.getByRole('list', { name: /^(zasoby|assets)$/i });
  await expect(skeleton).toHaveCount(0);
  await expect(realGrid.locator('> li').first()).toBeVisible();

  // Read the target folder's element count from its tile ("N elem."). The
  // first tile is a real folder (the "Bez przypisania" tile has no count and
  // is appended last).
  const firstFolder = page.getByTestId('folder-tile').first();
  await expect(firstFolder).toBeVisible();
  const tileText = (await firstFolder.innerText()).replace(/\s+/g, ' ');
  const countMatch = tileText.match(/(\d+)\s*elem/i);
  expect(countMatch).not.toBeNull();
  const targetCount = Number(countMatch?.[1]);
  const expectedSkeleton = Math.min(Math.max(targetCount, 1), 120);

  // Hold the next assets request so the switch stays visible long enough.
  let armed = false;
  await page.route('**/api/assets?**', async (route) => {
    if (armed) {
      await new Promise((r) => setTimeout(r, 1200));
    }
    await route.continue();
  });
  armed = true;

  // Double-click the folder tile (Explorer semantics, #2320).
  await firstFolder.dblclick();

  // While the folder loads the skeleton is shown (proves stale cards are
  // hidden) and it is sized to the TARGET folder, not the outgoing grid.
  await expect(skeleton).toBeVisible();
  await expect(page.locator('ul[aria-hidden="true"] > li')).toHaveCount(expectedSkeleton);

  // The files-header count shows the destination count during the switch —
  // not the previous view's stale total.
  await expect(page.getByTestId('files-count')).toHaveText(String(targetCount));

  // The real (labelled) grid is not rendered while switching — no stale cards.
  await expect(realGrid).toHaveCount(0);

  // After the request resolves the real grid returns and the skeleton is gone.
  // (No exact-count assertion on the settled grid — the list is currently
  // capped by the pagination bug tracked in #2613.)
  await expect(skeleton).toHaveCount(0, { timeout: 5000 });
  await expect(realGrid.locator('> li').first()).toBeVisible();
});
