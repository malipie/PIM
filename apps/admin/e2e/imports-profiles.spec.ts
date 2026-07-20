import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-IMP-02 (#498) — profiles hub smoke. Single login covers the
 * three behaviours (title + new profile CTA, grid/list toggle, empty
 * state) to stay inside the 5/IP/15min auth rate-limiter.
 */
test('imports profiles hub — title + grid/list toggle + new-profile CTA', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/profiles');

  // The in-page header was removed in #2671 — the "Nowy profil" CTA is the
  // hub's ready signal.
  const newProfile = page.getByRole('button', { name: /nowy profil|new profile/i }).first();
  await expect(newProfile).toBeVisible();

  // Grid → list toggle.
  const listToggle = page.getByRole('button', { name: /^lista$|^list$/i });
  await listToggle.click();
  await expect(listToggle).toHaveAttribute('aria-pressed', 'true');

  // Back to grid.
  const gridToggle = page.getByRole('button', { name: /^siatka$|^grid$/i });
  await gridToggle.click();
  await expect(gridToggle).toHaveAttribute('aria-pressed', 'true');
});
