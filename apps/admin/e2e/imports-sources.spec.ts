import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-IMP-03 (#500) — sources hub smoke. Single login covers the
 * three behaviours (title + add CTA, empty state for a fresh tenant,
 * dialog open) to stay inside the 5/IP/15min auth rate-limiter.
 */
test('imports sources hub — title + empty state + add dialog', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/sources');

  // Empty state for a fresh tenant (the in-page header was removed in #2671).
  await expect(
    page.getByRole('heading', { name: /brak skonfigurowanych źródeł|no sources yet/i }),
  ).toBeVisible();

  // Add-source CTA opens the SourceFormDialog.
  await page
    .getByRole('button', { name: /dodaj źródło|add source/i })
    .first()
    .click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.getByRole('heading', { name: /nowe źródło|new source/i })).toBeVisible();
});
