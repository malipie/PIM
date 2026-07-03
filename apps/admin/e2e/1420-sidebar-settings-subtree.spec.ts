import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * NUI-01 (#1420) — sidebar v2:
 *   1. The settings sub-navigation renders as an indented subtree under
 *      "Ustawienia" in the MAIN sidebar while any /settings/* route is
 *      active (deep links included), and disappears outside /settings.
 *   2. Custom ObjectTypes render like built-in items — no CUSTOM badge,
 *      no violet dashed treatment.
 */

test.describe('NUI-01 — settings subtree in main sidebar', () => {
  test('deep link renders subtree with active item; subtree collapses outside /settings', async ({
    page,
  }) => {
    await loginAsAdmin(page);

    await page.goto('/settings/users');
    const subtree = page.getByTestId('nav-settings-subtree');
    await expect(subtree).toBeVisible();

    // Group headers from settings-nav-data render inside the subtree.
    await expect(subtree).toContainText(/workspace/i);
    // Active item highlighted (aria-current from NavLink).
    const usersLink = subtree.getByRole('link', { name: /użytkownicy|users/i });
    await expect(usersLink).toHaveAttribute('aria-current', 'page');
    // Audit card sits at the bottom of the subtree.
    await expect(subtree).toContainText(/audyt zmian|audit log/i);

    // Navigating to another settings page keeps the subtree. DP-03 (#2033)
    // moved Channels/Locales to /modeling, so hop to Roles instead.
    await subtree.getByRole('link', { name: /role|roles/i }).click();
    await expect(page).toHaveURL(/\/settings\/roles/);
    await expect(subtree).toBeVisible();

    // Outside /settings the subtree unmounts.
    await page.goto('/dashboard');
    await expect(page.getByTestId('nav-settings-subtree')).toHaveCount(0);
  });

  test('custom ObjectType renders without CUSTOM badge or violet treatment', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/dashboard');

    const nav = page.locator('nav').first();
    await expect(nav.getByRole('link', { name: /dashboard/i })).toBeVisible();

    // No CUSTOM tag anywhere in the sidebar — holds in every environment.
    await expect(nav.getByText('CUSTOM', { exact: true })).toHaveCount(0);

    // Class-level check needs a custom ObjectType in the menu. The operator's
    // local DB ships "Usługi"; CI fixtures seed no custom OT — skip there.
    const customLink = nav.getByRole('link', { name: /usługi/i });
    // The effective menu loads async — give the custom OT entry a beat.
    const hasCustom = await customLink
      .waitFor({ state: 'visible', timeout: 10_000 })
      .then(() => true)
      .catch(() => false);
    test.skip(!hasCustom, 'No custom ObjectType in this environment seed');

    const className = (await customLink.getAttribute('class')) ?? '';
    expect(className).not.toContain('violet');
    expect(className).not.toContain('border-dashed');
  });
});
