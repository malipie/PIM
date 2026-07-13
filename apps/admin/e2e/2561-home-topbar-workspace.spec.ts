import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2561 — home page polish: the "Pulpit" concept is renamed to
 * "Workspace" and the topbar loses its disabled "history soon"
 * placeholder icon.
 *
 * Locks in three operator-requested changes on `/dashboard`:
 *   1. the topbar breadcrumb reads just "Workspace" (the redundant
 *      "/ Pulpit" segment was dropped from ROUTE_CRUMBS),
 *   2. the left nav's first item (link → /dashboard) is labelled
 *      "Workspace", not "Pulpit",
 *   3. the greyed-out disabled history button is gone — the active
 *      BulkSessionsPopover already covers the rollback/history surface
 *      (it self-hides under webdriver, so we only assert the removal).
 */

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    // Seed the i18next detector so the app resolves to `pl` regardless of
    // the Playwright profile locale (default en-US renders EN labels).
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('#2561 — home topbar shows Workspace breadcrumb, no Pulpit, no disabled history icon', async ({
  page,
}) => {
  await loginAsAdmin(page);

  // Attach the listener only AFTER login: the unauthenticated login screen
  // legitimately probes /api/auth/refresh (HttpOnly cookie is invisible
  // client-side) and that bootstrap 401 is out of scope (see #2199). We
  // also drop "Failed to load resource" lines — those are browser network
  // logs (the auth-bootstrap 401 race), not JS/React errors, which is what
  // this smoke assertion actually guards.
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    if (message.text().includes('Failed to load resource')) return;
    consoleErrors.push(message.text());
  });

  await page.goto('/dashboard');

  // 1. Breadcrumb — single "Workspace" segment, no "Pulpit".
  const breadcrumb = page.getByRole('navigation', { name: 'breadcrumb' });
  await expect(breadcrumb).toBeVisible();
  await expect(breadcrumb).toHaveText('Workspace');
  await expect(breadcrumb.getByText('Pulpit')).toHaveCount(0);

  // 2. Left menu — the dashboard entry is now "Workspace"; "Pulpit" is gone.
  const menuLink = page.getByRole('link', { name: 'Workspace', exact: true });
  await expect(menuLink.first()).toBeVisible();
  await expect(menuLink.first()).toHaveAttribute('href', '/dashboard');
  await expect(page.getByRole('link', { name: 'Pulpit' })).toHaveCount(0);

  // 3. No disabled "history soon" placeholder button in the topbar.
  await expect(page.getByRole('button', { name: 'Historia', exact: true })).toHaveCount(0);

  expect(consoleErrors).toEqual([]);
});
