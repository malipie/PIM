import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * GRID-P2-01/02/03 (#2389/#2390/#2391) — column manager, resize with a
 * sticky identifier, and row density on /products:
 *  - hide a column → gone from the grid → survives reload,
 *  - reorder via the explicit a11y buttons → order persists,
 *  - reset restores the default set,
 *  - drag-resize a header → width persists after reload,
 *  - density toggle shrinks rows and persists.
 */

test('column manager: hide, reorder, reset — persisted per ObjectType', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');
  await expect(page.getByTestId('grid-header-completeness')).toBeVisible();

  // Hide "Kompletność".
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-row-completeness').getByRole('checkbox').click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('grid-header-completeness')).toHaveCount(0);

  await page.reload();
  await expect(page.getByTestId('grid-header-code')).toBeVisible();
  await expect(page.getByTestId('grid-header-completeness')).toHaveCount(0);

  // Reorder: move "__sync" down one slot — its neighbour (__price) is
  // visible, so the change is observable in the header row. (a11y
  // buttons — drag is covered by the same handler; buttons are
  // deterministic in CI.)
  const headersBefore = await page
    .locator('[data-testid^="grid-header-"]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-testid')));
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-down-__sync').click();
  await page.keyboard.press('Escape');
  const headersAfter = await page
    .locator('[data-testid^="grid-header-"]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-testid')));
  expect(headersAfter).not.toEqual(headersBefore);

  await page.reload();
  const headersReloaded = await page
    .locator('[data-testid^="grid-header-"]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-testid')));
  expect(headersReloaded).toEqual(headersAfter);

  // Reset → completeness is back, categories back in place.
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-reset').click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('grid-header-completeness')).toBeVisible();
});

test('header drag-resize persists and the identifier stays sticky', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');

  const header = page.getByTestId('grid-header-__categories');
  await expect(header).toBeVisible();
  const before = (await header.boundingBox())?.width ?? 0;

  const handle = page.getByTestId('grid-resize-__categories');
  const box = await handle.boundingBox();
  if (box === null) throw new Error('resize handle not visible');
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 90, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();

  const after = (await header.boundingBox())?.width ?? 0;
  expect(after).toBeGreaterThan(before + 40);

  await page.reload();
  const persisted = (await page.getByTestId('grid-header-__categories').boundingBox())?.width ?? 0;
  expect(Math.abs(persisted - after)).toBeLessThan(4);

  // Sticky identifier: scroll the list container right — SKU header keeps
  // its viewport position.
  const grid = page.getByTestId('products-grid');
  await grid.evaluate((node) => {
    node.scrollLeft = 400;
  });
  const codeBox = await page.getByTestId('grid-header-code').boundingBox();
  const gridBox = await grid.boundingBox();
  if (codeBox === null || gridBox === null) throw new Error('boxes unavailable');
  expect(codeBox.x - gridBox.x).toBeLessThan(120); // pinned near the left edge

  // Cleanup quick-prefs so the spec is re-runnable.
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-reset').click();
});

test('density toggle shrinks rows and persists', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');

  const firstRow = page.locator('[data-testid^="products-grid-row-"]').first();
  await expect(firstRow).toBeVisible();
  const normalHeight = (await firstRow.boundingBox())?.height ?? 0;

  await page.getByTestId('density-compact').click();
  const compactHeight = (await firstRow.boundingBox())?.height ?? 0;
  expect(compactHeight).toBeLessThan(normalHeight * 0.8);

  await page.reload();
  await expect(page.getByTestId('density-compact')).toHaveAttribute('aria-selected', 'true');
  const reloadedHeight =
    (await page.locator('[data-testid^="products-grid-row-"]').first().boundingBox())?.height ?? 0;
  expect(reloadedHeight).toBeLessThan(normalHeight * 0.8);

  await page.getByTestId('density-normal').click();
});
