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
  await page.getByTestId('grid-header-code').waitFor();
  // Clear any column prefs left by earlier tests so we start from the
  // ObjectType default (localStorage is shared across specs).
  await page.evaluate(() => {
    for (const k of Object.keys(localStorage)) {
      if (/pim\.objectList\..*\.columns$/.test(k)) localStorage.removeItem(k);
    }
  });
  await page.reload();
  await expect(page.getByTestId('grid-header-completeness')).toBeVisible();

  // Hide "Kompletność".
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-row-completeness').getByRole('checkbox').click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('grid-header-completeness')).toHaveCount(0);

  await page.reload();
  await expect(page.getByTestId('grid-header-code')).toBeVisible();
  await expect(page.getByTestId('grid-header-completeness')).toHaveCount(0);

  // Reorder via the manager and verify against the manager's own row
  // order — deterministic regardless of which columns are hidden (the
  // manager lists every column; moving one always changes that order,
  // unlike grid headers where a move past a hidden column is invisible).
  const managerOrder = async (): Promise<Array<string | null>> => {
    await page.getByTestId('column-manager-trigger').click();
    await page.locator('[data-testid^="column-manager-row-"]').first().waitFor();
    const order = await page
      .locator('[data-testid^="column-manager-row-"]')
      .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-testid')));
    await page.keyboard.press('Escape');
    return order;
  };

  const orderBefore = await managerOrder();
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-up-__variant').click();
  await page.keyboard.press('Escape');
  const orderAfter = await managerOrder();
  expect(orderAfter).not.toEqual(orderBefore);

  await page.reload();
  await expect(page.getByTestId('grid-header-code')).toBeVisible();
  await page.getByTestId('grid-header-__variant').waitFor();
  const orderReloaded = await managerOrder();
  expect(orderReloaded).toEqual(orderAfter);

  // Reset → completeness is back.
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
  await page.waitForTimeout(300);
  const before = (await header.boundingBox())?.width ?? 0;

  const handle = page.getByTestId('grid-resize-__categories');
  await handle.scrollIntoViewIfNeeded();
  const box = await handle.boundingBox();
  if (box === null) throw new Error('resize handle not visible');
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 90, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();

  const after = (await header.boundingBox())?.width ?? 0;
  expect(after).toBeGreaterThan(before + 40);

  await page.reload();
  await page.waitForTimeout(300);
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
