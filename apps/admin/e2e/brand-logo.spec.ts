import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * The sidebar brand mark is the "harmon PIM" horizontal lockup from the design
 * system ("Lockup podstawowy · poziomy"), shipped as a single image asset.
 * Guards against a regression back to the old "P" square mark.
 */
test('sidebar renders the harmon brand lockup image', async ({ page }) => {
  await loginAsAdmin(page);

  const logo = page.getByRole('complementary').first().getByRole('img', { name: 'harmon PIM' });
  await expect(logo).toBeVisible();
  // The asset must actually decode (not a broken src).
  await expect
    .poll(() => logo.evaluate((img: HTMLImageElement) => img.naturalWidth))
    .toBeGreaterThan(0);
});
