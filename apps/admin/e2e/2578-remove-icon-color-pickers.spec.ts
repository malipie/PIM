import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2578 — the icon/color selection was removed from the entry-definition
 * forms (ObjectType wizard, Category create) and the Smart Preset modal.
 * Defaults are sent silently so the BE contracts stay satisfied; the UI
 * simply no longer exposes a picker. This spec asserts the pickers are gone
 * on the two modeling forms (the Smart Preset save path is covered by
 * ptr-01, which now saves without touching an icon field).
 */
test('2578: ObjectType wizard no longer shows icon/color pickers', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/modeling/object-types/new');

  // Wizard step 1 renders inline (progressbar step indicator).
  await expect(page.getByRole('progressbar')).toBeVisible();

  // The identification step keeps Name + Code but no icon/color fields.
  await expect(page.getByText(/^Ikona$/)).toHaveCount(0);
  await expect(page.getByText(/^Kolor$/)).toHaveCount(0);
});

test('2578: Category create no longer shows the visualization (icon) section', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/modeling/categories/new');

  // The create form renders; the "Wizualizacja" (icon picker) section is gone.
  await expect(page.getByText(/Nowa kategoria/i).first()).toBeVisible();
  await expect(page.getByText(/Wizualizacja/i)).toHaveCount(0);
});
