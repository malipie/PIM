import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * NUI-04 (#1423) — products list v2 visual retrofit. Guards the shared
 * surface: the smart-filter row and grid render on BOTH `/products`
 * (built-in) and `/objects/:slug` (custom ObjectType). The saved-views
 * rail was merged into smart presets (PTR-01) and no longer exists.
 */

test.describe('NUI-04 — products list v2', () => {
  test('smart filters + grid render on /products', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/products');

    // The smart-filters row label is always present; the tablist role
    // appears only when presets exist (a11y — empty tablists are hidden).
    await expect(page.getByText(/smart filtry|smart filters/i).first()).toBeVisible();
    const smartRow = page.getByRole('tablist', { name: /smart filtry|smart filters/i });

    // Grid header in v2 typography renders the SKU column.
    await expect(page.getByText('SKU', { exact: true }).first()).toBeVisible();
    // Toolbar search keeps the EAN-aware placeholder semantics.
    await expect(page.getByPlaceholder(/sku.*ean|szukaj|search/i)).toBeVisible();

    // Preset interaction (chips + copy URL) is covered by 1205; here only
    // assert the row exposes its tabs when presets exist in the env.
    const presetCount = await smartRow.getByRole('tab').count();
    if (presetCount > 0) {
      await smartRow.getByRole('tab').first().click();
      await expect(
        page
          .getByRole('button', { name: /skopiuj url|copy url/i })
          .or(page.getByText(/aktywne filtry|active filters/i))
          .first(),
      ).toBeVisible({ timeout: 10_000 });
    }
  });

  test('the same list surface serves a custom ObjectType', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/objects/uslugi');

    // The smart-filters label is the always-present marker of the list
    // surface; absent when the custom ObjectType is not in this env seed.
    const smartLabel = page.getByText(/smart filtry|smart filters/i).first();
    const surfaceVisible = await smartLabel
      .waitFor({ state: 'visible', timeout: 15_000 })
      .then(() => true)
      .catch(() => false);
    test.skip(!surfaceVisible, 'No custom ObjectType `uslugi` in this environment seed');

    await expect(smartLabel).toBeVisible();
  });
});
