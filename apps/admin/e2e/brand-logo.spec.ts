import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * The sidebar brand mark is the "harmon PIM" horizontal lockup from the design
 * system (Design System.html — "Lockup podstawowy · poziomy"): sygnet + Manrope
 * wordmark + orange PIM badge. Guards against a regression back to the old
 * "P" square / plain app-title mark.
 */
test('sidebar renders the harmon brand lockup', async ({ page }) => {
  await loginAsAdmin(page);

  const sidebar = page.getByRole('complementary').first();
  await expect(sidebar.getByText('harmon', { exact: true })).toBeVisible();
  await expect(sidebar.getByText('PIM', { exact: true })).toBeVisible();
});
