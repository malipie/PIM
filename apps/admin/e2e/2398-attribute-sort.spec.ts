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

  // expect-polling between clicks — clicking mid-rerender double-fires
  // the cycle (race caught on the live run); the URL assert proves the
  // backend request shape without racing waitForRequest.
  await page.getByTestId('grid-sort-code').click();
  await expect(page.getByTestId('grid-header-code')).toHaveAttribute('aria-sort', 'ascending');
  await expect(page).toHaveURL(/sort=code%3Aasc|sort=code:asc/);

  await page.getByTestId('grid-sort-code').click();
  await expect(page.getByTestId('grid-header-code')).toHaveAttribute('aria-sort', 'descending');
  await expect(page).toHaveURL(/sort=code%3Adesc|sort=code:desc/);

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

  // Reveal the first hidden attribute column from the full catalogue.
  await page.getByTestId('column-manager-trigger').click();
  const hiddenRow = page
    .locator('[data-testid^="column-manager-row-"]')
    .filter({ has: page.getByRole('checkbox', { checked: false }) })
    .first();
  const rowTestId = await hiddenRow.getAttribute('data-testid');
  test.skip(rowTestId === null, 'No hidden attribute columns in this environment seed');
  const attrCode = (rowTestId ?? '').replace('column-manager-row-', '');
  await hiddenRow.getByRole('checkbox').click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId(`grid-header-${attrCode}`)).toBeVisible();

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
