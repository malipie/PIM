import { expect, type Page, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

// #41 (epic 0.4) restored the `/api/products` sugar path on `CatalogObject`
// (`GET /api/products` → 200 with the catalog objects), so the post-login
// `/products` page and the login → dashboard redirect both resolve again.
// These auth happy-path + security specs (login, logout, silent refresh,
// token-not-in-localStorage) are no longer gated — AUD-022 / AUD-023.
//
// Selector notes after the UI-03 redesign:
//   - the products list <h1> renders the ObjectType label (e.g. "Products" /
//     "Produkty" / "Product"), so we assert on the stable products-list region
//     (`aria-label`) + the /products URL instead of a brittle heading word.
//   - logout moved into the sidebar user-menu dropdown (a `menuitem`, not a
//     top-level button) — open the "User menu" trigger first.

/**
 * Open the sidebar user-menu and click "Wyloguj / Sign out". The control is a
 * Radix DropdownMenuItem (role `menuitem`) behind the "User menu" trigger.
 */
async function clickLogout(page: Page): Promise<void> {
  await page.getByRole('button', { name: /menu użytkownika|user menu/i }).click();
  await page.getByRole('menuitem', { name: /wyloguj|sign out/i }).click();
}

/**
 * Assert the products list page rendered for an authenticated session. The
 * AuthedRoute guard runs a cold-load silent refresh on a full navigation; the
 * labelled products-list region is the stable post-auth signal.
 */
async function expectProductsList(page: Page): Promise<void> {
  await expect(page).toHaveURL(/\/products$/);
  await expect(page.getByRole('region', { name: /lista produktów|products list/i })).toBeVisible({
    timeout: 15_000,
  });
}

test.describe('Authentication', () => {
  test('user can log in and lands on the products list', async ({ page }) => {
    await loginAsAdmin(page);
    // Dashboard is the new index after epik UI-03 #356; products list moved to /products.
    await page.goto('/products');
    await expectProductsList(page);
  });

  test('wrong password surfaces an inline error and stays on /login', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/e-?mail/i).fill(ADMIN_EMAIL);
    await page.getByLabel(/has[lł]o|password/i).fill('not-the-password');
    await page.getByRole('button', { name: /zaloguj|sign in/i }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('alert')).toBeVisible();
  });

  // #2618 — regression: visiting a protected route while signed out caches
  // `{authenticated: false}` in the auth-check query. Refine's useLogin
  // navigates to /dashboard *before* invalidating that cache, so AuthedRoute
  // used to read the stale `false` and bounce the user straight back to
  // /login on their FIRST successful login (the second attempt hit the
  // refreshed cache and worked). The direct-`/login` helper flow never seeds
  // the stale entry, which is why the other specs never caught it.
  test('first login after an unauthenticated bounce lands on dashboard', async ({
    page,
    context,
  }) => {
    await context.clearCookies();
    // Seed the stale auth-check cache: protected route → bounce to /login.
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);

    await page.getByLabel(/e-?mail/i).fill(ADMIN_EMAIL);
    await page.getByLabel(/has[lł]o|password/i).fill(ADMIN_PASSWORD);
    await page.getByRole('button', { name: /zaloguj|sign in/i }).click();

    // One submit must be enough — and the session must stick (a dashboard
    // element rendering proves we did not bounce back to /login mid-way).
    await expect(page).toHaveURL(/\/dashboard$/, { timeout: 15_000 });
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveURL(/\/dashboard$/);
  });

  test('an unauthenticated visit to /products bounces to /login', async ({ page, context }) => {
    // Ensure no token leaks in from a previous test in the same browser context.
    await context.clearCookies();
    await page.goto('/products');
    await expect(page).toHaveURL(/\/login$/);
  });

  test('logout clears the session and redirects to /login', async ({ page }) => {
    await loginAsAdmin(page);
    await clickLogout(page);
    await expect(page).toHaveURL(/\/login$/);
  });

  // Sanity check that the credentials we use everywhere actually match the
  // fixtures the API ships with — if this fails, every other test is bogus.
  test('seeded credentials match the fixtures', async ({ page }) => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await expect(page).toHaveURL(/\/dashboard$/);
  });

  test('hard reload preserves the session via silent refresh', async ({ page }) => {
    await loginAsAdmin(page);

    // The reload drops the in-memory access token. /api/auth/refresh against
    // the still-present HttpOnly cookie should put a new one back, and the
    // user must NOT be bounced to /login.
    const refreshSeen = page.waitForResponse(
      (response) =>
        response.url().includes('/api/auth/refresh') &&
        response.request().method() === 'POST' &&
        response.status() === 200,
    );
    await page.reload();
    await refreshSeen;

    await expect(page).toHaveURL(/\/dashboard$/);
  });

  test('access token is not stored in localStorage', async ({ page }) => {
    await loginAsAdmin(page);
    const stored = await page.evaluate(() => window.localStorage.getItem('pim.jwt'));
    expect(stored).toBeNull();
  });

  test('logout calls POST /api/auth/logout', async ({ page }) => {
    await loginAsAdmin(page);

    const logoutPosted = page.waitForRequest(
      (request) => request.url().includes('/api/auth/logout') && request.method() === 'POST',
    );
    await clickLogout(page);
    await logoutPosted;

    await expect(page).toHaveURL(/\/login$/);
  });
});
