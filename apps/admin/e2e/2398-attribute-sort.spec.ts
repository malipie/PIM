import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * GRID-P5-02/03 (#2398/#2399) — clickable sortable headers drive the
 * backend sort (assert the REQUEST per the Meili-index lesson — freshly
 * written rows may lag the search index, the order[] param is the
 * contract): system column via OrderById core fields, attribute column
 * via AttributeOrderFilter after revealing it in the column manager.
 */

test('system header click sorts via order[code] and cycles to desc', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');
  await expect(page.getByTestId('grid-header-code')).toBeVisible();

  // Await the sorted list RESPONSE before the next click — clicking
  // mid-refetch double-fires the cycle, and networkidle never settles
  // here (Mercure SSE keeps a connection open).
  const ascResponse = page.waitForResponse(
    (r) =>
      r.url().includes('/api/objects') && decodeURIComponent(r.url()).includes('order[code]=asc'),
  );
  await page.getByTestId('grid-sort-code').click();
  await ascResponse;
  await expect(page.getByTestId('grid-header-code')).toHaveAttribute('aria-sort', 'ascending');
  await expect(page).toHaveURL(/sort=code%3Aasc|sort=code:asc/);
  await page.waitForTimeout(600);

  await page.getByTestId('grid-sort-code').click();
  await expect(page.getByTestId('grid-header-code')).toHaveAttribute('aria-sort', 'descending');
  await expect(page).toHaveURL(/sort=code%3Adesc|sort=code:desc/);

  await page.waitForTimeout(600);

  // Sort state survives reload via the URL.
  await page.reload();
  await expect(page.getByTestId('grid-header-code')).toHaveAttribute('aria-sort', 'descending');

  // Third click switches off.
  await page.getByTestId('grid-sort-code').click();
  await expect(page.getByTestId('grid-header-code')).not.toHaveAttribute('aria-sort', /.*/);
});

test('revealed attribute column sorts via order[attribute.{code}]', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');
  await expect(page.getByTestId('grid-header-code')).toBeVisible();

  // Reveal the first hidden ATTRIBUTE column from the full catalogue —
  // status/updatedAt are hidden system columns (order[status], not
  // order[attribute.*]) and view columns are __-prefixed.
  await page.getByTestId('column-manager-trigger').click();
  const hiddenRows = page
    .locator('[data-testid^="column-manager-row-"]')
    .filter({ has: page.getByRole('checkbox', { checked: false }) });
  const count = await hiddenRows.count();
  let attrCode = '';
  for (let i = 0; i < count; i += 1) {
    const testId = (await hiddenRows.nth(i).getAttribute('data-testid')) ?? '';
    const key = testId.replace('column-manager-row-', '');
    if (key !== 'status' && key !== 'updatedAt' && !key.startsWith('__')) {
      attrCode = key;
      break;
    }
  }
  test.skip(attrCode === '', 'No hidden attribute columns in this environment seed');
  const hiddenRow = page.getByTestId(`column-manager-row-${attrCode}`);
  await hiddenRow.getByRole('checkbox').click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId(`grid-header-${attrCode}`)).toBeVisible();
  await page.waitForTimeout(600);

  const sortButton = page.getByTestId(`grid-sort-${attrCode}`);
  test.skip(!(await sortButton.isVisible()), 'Revealed attribute is not sortable (type rules)');

  const request = page.waitForRequest(
    (req) =>
      req.url().includes('/api/objects') &&
      decodeURIComponent(req.url()).includes(`order[attribute.${attrCode}]=asc`),
    { timeout: 15_000 },
  );
  await sortButton.click();
  const response = await (await request).response();
  expect(response?.status()).toBe(200);
  await expect(page.getByTestId(`grid-header-${attrCode}`)).toHaveAttribute(
    'aria-sort',
    'ascending',
  );

  // Cleanup quick-prefs for re-runs.
  await page.getByTestId('column-manager-trigger').click();
  await page.getByTestId('column-manager-reset').click();
});
