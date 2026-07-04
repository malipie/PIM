import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #1678 — the import wizard's first step is a tile screen mirroring the export
 * wizard's "Krok 1: Wybierz dane do eksportu": Produkty / Moduły własne /
 * Kategorie are selectable, while Schemat modułów + Atrybuty i grupy are
 * disabled ("soon") because the import pipeline creates objects per ObjectType,
 * not metadata. Picking a tile sets the target type and advances to Źródło.
 * (Replaces the earlier, wrong Combobox-in-StepSource approach.)
 */
test('#1678 — import wizard step 1 is the data-kind tile screen', async ({ page }) => {
  test.setTimeout(120_000);
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/new');

  // Step 1 — tiles, mirroring the export wizard.
  await expect(
    page.getByRole('heading', { name: /wybierz dane do importu|choose the data to import/i }),
  ).toBeVisible({ timeout: 20_000 });

  const productTile = page.getByRole('radio', { name: /produkty|products/i });
  const categoryTile = page.getByRole('radio', { name: /kategorie|categories/i });
  await expect(productTile).toBeVisible();
  await expect(categoryTile).toBeVisible();

  // The metadata tiles are disabled ("soon").
  await expect(page.getByRole('radio', { name: /schemat modułów|module schema/i })).toHaveAttribute(
    'aria-disabled',
    'true',
  );

  // Pick "Kategorie" → it becomes the selected tile → advance to Źródło.
  await categoryTile.click();
  await expect(categoryTile).toHaveAttribute('aria-checked', 'true');
  const nextButton = page.getByRole('button', { name: /dalej|next/i });
  await expect(nextButton).toBeEnabled();
  await nextButton.click();

  // Step 2 — Źródło: the upload control is visible and the old "Co importujesz?"
  // combobox is gone.
  await expect(page.getByText(/wgraj plik|upload a file/i).first()).toBeVisible({
    timeout: 20_000,
  });
  await expect(page.getByText(/co importujesz\?|what are you importing\?/i)).toHaveCount(0);
});
