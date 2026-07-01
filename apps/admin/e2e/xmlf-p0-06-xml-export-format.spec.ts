import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P0-06 — XML is unblocked as an ad-hoc export format (ADR-0023 §6.10).
 * It used to render as a disabled "wkrótce" tile; it is now active and
 * selectable alongside XLSX/CSV. The backend serialization + application/xml
 * response are covered by the API test + live smoke on POST /api/products/export.
 */
test('XML is an enabled, selectable export format tile', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/exports/new');

  // Step 0 — entity type: pick Products, then advance to the scope + format step.
  await page
    .getByRole('radio', { name: /Produkty|Products/ })
    .first()
    .click();
  await page.getByRole('button', { name: /Dalej|Next/ }).click();

  // Step 1 — format tiles. XML is now an active radio, not a disabled tile.
  const xmlTile = page.getByRole('radio', { name: /XML/ }).first();
  await expect(xmlTile).toBeVisible();
  await expect(xmlTile).not.toHaveAttribute('aria-disabled', 'true');

  await xmlTile.click();
  await expect(xmlTile).toHaveAttribute('aria-checked', 'true');
});
