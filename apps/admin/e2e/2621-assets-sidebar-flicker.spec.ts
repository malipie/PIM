import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2621 — entering /assets must not render the files section first and push
 * it down when the folder tiles arrive. The folder list used to live in a
 * local useState fetched on every mount, while the assets query is cached by
 * React Query — so on a warm re-entry (sidebar click) the cached asset cards
 * flashed at the top and the late folder tiles shoved them below the fold.
 * The folder list now shares the React Query cache (use-asset-folders.ts)
 * and on a cold load the files section holds its skeleton until the folder
 * list is in. Both tests delay `/api/asset-folders` to make the transition
 * observable; assertions are dataset-agnostic (CI fixtures have no folders —
 * only the static "Bez przypisania" tile).
 */

const FOLDERS_DELAY_MS = 1500;

test('warm re-entry renders folder tiles from cache, not after a refetch', async ({ page }) => {
  await loginAsAdmin(page);
  // Wait for the folder-list RESPONSE before counting tiles — the static
  // "Bez przypisania" tile renders before the fetch lands, so counting on
  // first-tile-visible would record 1 and make the re-entry assertion
  // trivially pass whatever the folder count is.
  const foldersLoaded = page.waitForResponse(
    (r) => r.url().includes('/api/asset-folders') && r.ok(),
  );
  await page.goto('/assets');
  await foldersLoaded;

  // Initial settle: folder tiles + files section out of its skeletons.
  const tiles = page.getByTestId('folder-tile');
  await expect(tiles.first()).toBeVisible();
  await expect(page.getByTestId('folder-skeleton')).toHaveCount(0);
  await expect(page.locator('ul[aria-hidden="true"]')).toHaveCount(0);
  await page.waitForTimeout(300);
  const settledTiles = await tiles.count();

  // Delay subsequent folder-list requests — a remount that depended on the
  // network could not show the tiles before the delay elapses.
  let armed = false;
  await page.route('**/api/asset-folders**', async (route) => {
    if (armed) {
      await new Promise((r) => setTimeout(r, FOLDERS_DELAY_MS));
    }
    await route.continue();
  });
  armed = true;

  // Leave to another page and wait until the assets page actually UNMOUNTS
  // (tiles gone from the DOM). The URL flips immediately, but the React
  // transition keeps the old tree mounted until the lazy products chunk is
  // ready — returning before the unmount would keep the folder state alive
  // and make this test pass without exercising the remount at all.
  await page
    .getByRole('link', { name: /produkty|products/i })
    .first()
    .click();
  await expect(page).not.toHaveURL(/\/assets/);
  await expect(tiles).toHaveCount(0);
  await page
    .getByRole('link', { name: /multimedia/i })
    .first()
    .click();

  // All folder tiles render from the cache — the 1s timeout is shorter than
  // the network delay, so a mount that waited for the refetch fails here.
  await expect(tiles).toHaveCount(settledTiles, { timeout: 1000 });
  await expect(page.getByTestId('folder-skeleton')).toHaveCount(0);
  await expect(page.getByTestId('files-count')).toBeVisible();
});

test('cold load holds the files section until the folder list is in', async ({ page }) => {
  await loginAsAdmin(page);

  await page.route('**/api/asset-folders**', async (route) => {
    await new Promise((r) => setTimeout(r, FOLDERS_DELAY_MS));
    await route.continue();
  });

  await page.goto('/assets');

  // While the folder list is in flight: folder skeleton + files skeleton,
  // no real cards (even though /api/assets is not delayed and resolves).
  const folderSkeleton = page.getByTestId('folder-skeleton');
  await expect(folderSkeleton).toBeVisible();
  const realGrid = page.getByRole('list', { name: /^(zasoby|assets)$/i });
  await expect(realGrid).toHaveCount(0);
  await page.waitForTimeout(500);
  await expect(realGrid).toHaveCount(0);
  await expect(folderSkeleton).toBeVisible();

  // Once the folder list resolves, tiles and files appear together.
  await expect(page.getByTestId('folder-tile').first()).toBeVisible({
    timeout: FOLDERS_DELAY_MS + 3000,
  });
  await expect(folderSkeleton).toHaveCount(0);
  await expect(page.locator('ul[aria-hidden="true"]')).toHaveCount(0, { timeout: 5000 });
});
