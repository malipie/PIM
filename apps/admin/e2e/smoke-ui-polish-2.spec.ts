import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * Post-smoke UI polish (operator round 2): the API-tokens page now explains
 * what tokens are for (#2573), the ObjectType wizard preview no longer shows
 * an icon/color badge (#2578 follow-up), and interactive buttons carry a
 * pointer cursor (#2567 UX — the shared Button base).
 */
test('api-tokens page explains its purpose', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/settings/api-tokens');
  await expect(page.getByText(/Authorization: Token cortex/)).toBeVisible();
});

test('ObjectType wizard preview shows no icon/color badge', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/modeling/object-types/new');
  await expect(page.getByRole('progressbar')).toBeVisible();
  await expect(page.getByText(/^Ikona$/)).toHaveCount(0);
  await expect(page.getByText(/^Kolor$/)).toHaveCount(0);
});

test('primary buttons carry a pointer cursor', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/settings/api-tokens');
  // The "create token" CTA is a shared <Button>; the base now sets the cursor.
  const cta = page.getByRole('button', { name: /token/i }).first();
  await expect(cta).toBeVisible();
  await expect(cta).toHaveCSS('cursor', 'pointer');
});
