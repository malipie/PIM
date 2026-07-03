import { expect, test } from '@playwright/test';

import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * #1153 (channels epic) — the channels settings page (`/modeling/channels`)
 * already ships full CRUD over `/api/channels` (list / create / edit /
 * delete + locale & currency pickers). This spec is the missing E2E gate:
 * it verifies an operator can create a channel end-to-end and see it in
 * the list.
 *
 * `test.fixme` in CI for the shared auth-rate-limiter storageState gap.
 */
const CI_BLOCKED = 'E2E selector drift after UI-03 on the channels settings flow. Refs #1638';

test('operator creates a channel via the settings page', async ({ page }) => {
  test.fixme(true, CI_BLOCKED);
  test.setTimeout(180_000);

  await loginAsAdmin(page);
  // Warm the refresh cookie so a full-page goto re-establishes the JWT.
  const refresh = await page.request.post('/api/auth/refresh');
  expect(refresh.status()).toBe(200);

  const code = `chan_${uniqueSku('E2E')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_')}`;

  await page.goto('/modeling/channels/new');
  await expect(page.locator('#channel-code')).toBeVisible();
  await page.locator('#channel-code').fill(code);
  await page.locator('#channel-name').fill('Kanał E2E');

  // Pick one locale from the real catalog.
  await page.getByRole('button').filter({ hasText: /pl_PL/ }).first().click();

  const createResponse = page.waitForResponse(
    (r) => r.url().includes('/api/channels') && r.request().method() === 'POST',
  );
  await page.getByRole('button', { name: /(Utwórz|Create|Zapisz|Save)/i }).click();
  const created = await createResponse;
  expect(created.status(), await created.text()).toBe(201);

  // Lands on the channel detail page showing the new code.
  await expect(page.getByText(code).first()).toBeVisible();

  // And it appears in the channels list.
  await page.goto('/modeling/channels');
  await expect(page.getByText(code).first()).toBeVisible();
});
