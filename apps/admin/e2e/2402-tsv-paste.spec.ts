import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * GRID-P6-03 (#2402) — typed TSV paste onto the Excel grid. Paste from a
 * selected (non-editing) anchor cell coerces each cell to its column
 * type and reports applied vs skipped cells in a toast. Read-only cells
 * (SKU) are skipped. Browser clipboard (permissions granted) so it runs
 * on CI without the pim.localhost DNS caveat.
 */

test.use({ permissions: ['clipboard-read', 'clipboard-write'] });

test('pastes a TSV block, coercing values and reporting skips', async ({ page }) => {
  test.setTimeout(90_000);
  await loginAsAdmin(page);
  await page.goto('/products');
  await page.getByTestId('grid-header-code').waitFor();
  await page.getByRole('tab', { name: 'Excel' }).click();
  // Wait for the real schema columns (Nazwa) — the fallback set has no
  // editable column at index 1, so pasting before load skips everything.
  await expect(page.getByRole('columnheader', { name: 'Nazwa' })).toBeVisible();

  // A 2-column block: col A lands on SKU (read-only → skipped), col B on
  // the next column (name, editable → applied).
  const value = `Paste-${Date.now().toString(36)}`;
  await page.evaluate((val) => {
    return navigator.clipboard.writeText(`skipped-a\t${val}-1\nskipped-b\t${val}-2`);
  }, value);

  // Select the read-only SKU anchor (no edit mode) and paste. The commit
  // PATCH is async; wait for it explicitly (the toast fires synchronously
  // before the request resolves).
  const patch = page.waitForResponse(
    (r) => /\/api\/objects\/[^/]+$/.test(r.url()) && r.request().method() === 'PATCH',
    { timeout: 15_000 },
  );
  await page.locator('table tbody tr').first().locator('td').nth(0).click();
  await page.keyboard.press('Control+v');

  // The batch toast reports the paste (some applied, some skipped since
  // the SKU column is read-only).
  await expect(page.getByText(/wklejono|pasted/i)).toBeVisible({ timeout: 10_000 });
  const patchResp = await patch;
  expect(patchResp.status()).toBe(200);
  expect(patchResp.request().postData() ?? '').toContain('name');

  // The pasted name value persists after reload.
  await page.reload();
  await page.getByRole('tab', { name: 'Excel' }).click();
  await expect(
    page
      .locator('table tbody td')
      .filter({ hasText: `${value}-1` })
      .first(),
  ).toBeVisible();
});
