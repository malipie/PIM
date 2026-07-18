import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * NUI-01 (#1420) — sidebar v2:
 *   1. The settings sub-navigation renders as an indented subtree under
 *      "Ustawienia" in the MAIN sidebar while any /settings/* route is
 *      active (deep links included), and disappears outside /settings.
 *   2. Custom ObjectTypes render like built-in items — no CUSTOM badge,
 *      no violet dashed treatment.
 *   3. #2616 — the "Ustawienia" parent is a toggle button (like
 *      "Integracje"): clicking it collapses/expands the subtree without
 *      navigating.
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

  test('clicking "Ustawienia" toggles the subtree without navigating (#2616)', async ({ page }) => {
    await loginAsAdmin(page);

    await page.goto('/settings/users');
    const subtree = page.getByTestId('nav-settings-subtree');
    await expect(subtree).toBeVisible();

    // Scope to the sidebar nav — topbar/user-menu buttons could also match.
    const parent = page
      .locator('nav')
      .first()
      .getByRole('button', { name: /^(ustawienia|settings)$/i });
    await expect(parent).toHaveAttribute('aria-expanded', 'true');

    // First click collapses — the original bug: a second click on the same
    // entry did nothing because visibility was derived from the route alone.
    await parent.click();
    await expect(page.getByTestId('nav-settings-subtree')).toHaveCount(0);
    await expect(parent).toHaveAttribute('aria-expanded', 'false');
    await expect(page).toHaveURL(/\/settings\/users/);

    // Second click re-expands, still without navigating.
    await parent.click();
    await expect(page.getByTestId('nav-settings-subtree')).toBeVisible();
    await expect(parent).toHaveAttribute('aria-expanded', 'true');
    await expect(page).toHaveURL(/\/settings\/users/);
  });

  test('custom ObjectType renders without CUSTOM badge or violet treatment', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/dashboard');

    const nav = page.locator('nav').first();
    // The dashboard entry was renamed "Pulpit" → "Workspace" (#2561).
    await expect(nav.getByRole('link', { name: 'Workspace', exact: true })).toBeVisible();

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
