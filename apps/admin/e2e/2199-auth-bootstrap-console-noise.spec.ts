import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * #2199 — a hard reload of an authed page used to emit `401 GET /api/auth/me`
 * console noise: the in-memory access token is empty right after a full
 * navigation, and optimistic requests raced the in-flight refresh instead of
 * waiting for it (the retry healed the data, not the console). Listeners are
 * attached only AFTER login: the unauthenticated login screen legitimately
 * probes `POST /api/auth/refresh` (HttpOnly cookie cannot be checked
 * client-side), and that 401 is out of scope here.
 */
test('settings/users load emits no 401s after login (auth bootstrap race)', async ({ page }) => {
  await loginAsAdmin(page);

  const unauthorized: string[] = [];
  page.on('response', (r) => {
    if (r.status() === 401) unauthorized.push(`${r.request().method()} ${r.url()}`);
  });

  await page.goto('https://pim.localhost/settings/users');
  await expect(page.getByRole('heading').first()).toBeVisible();
  // Hard reload = fresh document with an empty in-memory token — the exact
  // shape that used to race the refresh.
  await page.reload();
  await expect(page.getByRole('heading').first()).toBeVisible();
  // Give late queries (roles catalogue, identity) time to settle.
  await page.waitForTimeout(3000);

  expect(unauthorized).toEqual([]);
});
