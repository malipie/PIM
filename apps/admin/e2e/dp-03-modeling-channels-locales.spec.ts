import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-03 (#2033) — Channels + Locales moved from Settings to Modeling.
 *
 * Single test with one login (auth-rate-limiter is 5/IP/15min, shared
 * across the run): tablist shows 6 tabs, both new tabs render their list
 * surfaces, old /settings/* URLs redirect, and the settings subtree no
 * longer offers the moved entries.
 */
test('DP-03 — channels + locales live under /modeling with back-compat redirects', async ({
  page,
}) => {
  await loginAsAdmin(page);

  // 1. Modeling tablist renders 6 tabs.
  await page.goto('/modeling');
  await expect(page).toHaveURL(/\/modeling\/object-types$/);
  const tablist = page.getByRole('tablist', { name: /modeling sections|sekcje modelowania/i });
  await expect(tablist).toBeVisible();
  await expect(tablist.getByRole('tab')).toHaveCount(6);
  await expect(tablist.getByRole('tab', { name: /channels|kanały/i })).toBeVisible();
  await expect(tablist.getByRole('tab', { name: /locales|wersje językowe/i })).toBeVisible();

  // 2. Channels tab navigates to the list and highlights itself.
  await tablist.getByRole('tab', { name: /channels|kanały/i }).click();
  await expect(page).toHaveURL(/\/modeling\/channels$/);
  await expect(tablist.getByRole('tab', { name: /channels|kanały/i })).toHaveAttribute(
    'aria-selected',
    'true',
  );

  // 3. Locales tab renders the tenant-locales list surface.
  await tablist.getByRole('tab', { name: /locales|wersje językowe/i }).click();
  await expect(page).toHaveURL(/\/modeling\/locales$/);

  // 4. Old settings URLs redirect (bookmarks keep working).
  await page.goto('/settings/channels');
  await expect(page).toHaveURL(/\/modeling\/channels$/);
  await page.goto('/settings/locales');
  await expect(page).toHaveURL(/\/modeling\/locales$/);

  // 5. Settings subtree no longer lists the moved entries.
  await page.goto('/settings/users');
  const subtree = page.getByTestId('nav-settings-subtree');
  await expect(subtree).toBeVisible();
  await expect(subtree.getByRole('link', { name: /kanały|channels/i })).toHaveCount(0);
  await expect(subtree.getByRole('link', { name: /wersje językowe|locales/i })).toHaveCount(0);
});
