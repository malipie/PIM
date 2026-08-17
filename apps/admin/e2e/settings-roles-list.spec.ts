import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * RBAC-P5-005 (#695) — Settings → Roles list smoke. Updated post-#865
 * UI re-align (table → cards) so locators target the new layout.
 *
 * One login covers everything visible — the auth-rate-limiter is shared
 * across the suite and other RBAC specs already chew through 4 of the 5
 * attempts per 15min window.
 */
test('Settings → Roles list — smoke', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/settings/roles');

  await page.waitForResponse(
    (response) => response.url().includes('/api/roles') && response.request().method() === 'GET',
    { timeout: 30_000 },
  );

  // Heading "Role i uprawnienia" / "Roles & permissions" + create CTA
  // both render in the toolbar card after the #865 re-align. The CTA is
  // gated behind `user.write`; admin@demo holds it so the button is
  // clickable, not just present.
  await expect(
    page.getByRole('heading', { level: 2, name: /role i uprawnienia|roles & permissions/i }),
  ).toBeVisible({ timeout: 30_000 });
  await expect(
    page.getByRole('button', { name: /stwórz custom role|create custom role/i }),
  ).toBeVisible();

  // 5-tab filter pills introduced in #865 — `Wszystkie / Platform /
  // Tenant / System / Custom`. Assert two representative ones rather
  // than every label so the spec stays robust to copy tweaks.
  await expect(page.getByRole('button', { name: /^platform$/i })).toBeVisible();
  await expect(page.getByRole('button', { name: /^custom$/i })).toBeVisible();

  // Seeded tenant roles render as rounded-3xl cards. Each role name
  // surfaces twice (the card identity heading plus the role chip in the
  // right-hand actions area), so `.first()` keeps strict-mode happy.
  //
  // #2881 — `super admin` is deliberately absent. It is a GLOBAL row
  // shared by every tenant, so listing it offered an edit of an object
  // that does not belong to this tenant (and the write path refuses it).
  // This assertion used to require it; the reversal is the change, not a
  // casualty of it.
  await expect(page.getByText(/super admin/i)).toHaveCount(0);
  await expect(page.getByText(/catalog manager/i).first()).toBeVisible();
  await expect(page.getByText(/integration manager/i).first()).toBeVisible();
  await expect(page.getByText('Viewer', { exact: true }).first()).toBeVisible();
  // At least one `system` badge (lowercase pill) must be visible.
  await expect(page.getByText(/^system$/i).first()).toBeVisible();
});
