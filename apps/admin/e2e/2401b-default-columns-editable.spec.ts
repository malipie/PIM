import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * GRID-P6-02 follow-up (#2401) — the default product Excel view surfaces
 * the real, editable `name` and `price` attribute columns (not the
 * read-only derived view-seeds), so Nazwa and Cena are inline-editable
 * out of the box. Computed (completeness/sync), relation (categories)
 * and derived (variant) columns stay read-only.
 */

test('Nazwa and Cena are editable by default; computed columns are not', async ({ page }) => {
  test.setTimeout(90_000);
  await loginAsAdmin(page);
  await page.goto('/products');
  await page.getByTestId('grid-header-code').waitFor();
  await page.getByRole('tab', { name: 'Excel' }).click();
  await page.getByRole('columnheader', { name: 'Nazwa' }).waitFor();

  const headers = page.locator('table thead th');
  const count = await headers.count();
  const indexOf = async (label: string): Promise<number> => {
    for (let i = 0; i < count; i += 1) {
      if ((await headers.nth(i).innerText()).trim() === label) return i;
    }
    return -1;
  };
  const cellEditable = async (col: number): Promise<boolean> => {
    const cls =
      (await page.locator('table tbody tr').first().locator('td').nth(col).getAttribute('class')) ??
      '';
    return !cls.includes('bg-muted');
  };

  const cenaIdx = await indexOf('Cena');
  expect(cenaIdx).toBeGreaterThanOrEqual(0);
  expect(await cellEditable(cenaIdx)).toBe(true);
  expect(await cellEditable(await indexOf('Nazwa'))).toBe(true);
  // Computed / relation columns stay read-only.
  expect(await cellEditable(await indexOf('Kompletność'))).toBe(false);
  expect(await cellEditable(await indexOf('Kanały'))).toBe(false);

  // Editing Cena PATCHes the price attribute with the {amount, currency}
  // envelope and persists after reload.
  const amount = String(100 + Math.floor(Math.random() * 800));
  const patch = page.waitForResponse(
    (r) => /\/api\/objects\/[^/]+$/.test(r.url()) && r.request().method() === 'PATCH',
    { timeout: 15_000 },
  );
  await page.locator('table tbody tr').first().locator('td').nth(cenaIdx).click();
  await page.keyboard.type(amount);
  await page.keyboard.press('Enter');
  const resp = await patch;
  expect(resp.status()).toBe(200);
  expect(resp.request().postData() ?? '').toContain('"price"');
  expect(resp.request().postData() ?? '').toContain('"amount"');

  await page.reload();
  await page.getByRole('tab', { name: 'Excel' }).click();
  await page.getByRole('columnheader', { name: 'Nazwa' }).waitFor();
  await expect(page.locator('table tbody tr').first().locator('td').nth(cenaIdx)).toContainText(
    amount,
  );
});
