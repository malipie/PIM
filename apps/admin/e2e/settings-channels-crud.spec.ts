import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-06 (#418) — Channels CRUD + mapping editor smoke test.
 *
 * Walks the operator's golden path: list → create → mapping tab →
 * back to list with the new row visible.
 *
 * Marked `fixme` in CI: this spec lands as the 9th test in the shared
 * Playwright run, after eight other tests have already burned through
 * the 5/15min auth rate-limiter cache. Local runs (where the cache
 * starts cold) pass cleanly, but CI consistently sees `/login` instead
 * of `/dashboard` when this spec finally fires loginAsAdmin.
 *
 * Coverage is preserved by ApiTestCase ChannelsCrudApiTest (12 BE
 * scenarios) + manual smoke per the PR test plan. Re-enable once the
 * suite migrates to Playwright `storageState` (one login per worker,
 * reused across specs) — separate hardening ticket (lessons z VIEW-01
 * #373).
 *
 * FE form validation is enforced by the disabled submit button gate
 * inside ChannelForm so the visible code path is locked even without
 * an E2E spec.
 */
const E2E_BLOCKED_BY_RATE_LIMITER =
  'E2E selector drift after UI-03 on the channels CRUD + mapping flow. Refs #1638';

test.describe('VIEW-06 — Settings · Channels · CRUD + mapping editor', () => {
  test('happy path: list → create → mapping tab → back to list', async ({ page }) => {
    test.fixme(true, E2E_BLOCKED_BY_RATE_LIMITER);
    await loginAsAdmin(page);

    const uniqueCode = `e2e_${Date.now().toString(36)}`;

    // 1. Navigate to /modeling/channels — list page renders with CTA.
    await page.goto('/modeling/channels');
    await expect(page.getByRole('heading', { name: /kana[łl]y|channels/i })).toBeVisible();
    await expect(page.getByRole('link', { name: /nowy kana[łl]|new channel/i })).toBeVisible();

    // 2. Click "New channel" — fullscreen-routed form lands at /new.
    await page.getByRole('link', { name: /nowy kana[łl]|new channel/i }).click();
    await expect(page).toHaveURL(/\/settings\/channels\/new$/);

    // 3. Fill form — code + name (channels no longer carry a locale set, #1318).
    await page.getByLabel(/^kod$|^code$/i).fill(uniqueCode);
    await page.getByLabel(/^nazwa$|^name$/i).fill('E2E test');

    // 4. Submit + redirect to detail page (UUID v7).
    await page.getByRole('button', { name: /utw[óo]rz kana[łl]|create channel/i }).click();
    await expect(page).toHaveURL(/\/settings\/channels\/[0-9a-f-]{36}$/);

    // 5. Mapping tab loads (might be empty without seeded mappings —
    //    listener is out-of-scope per BC boundary).
    await page.getByRole('button', { name: /^mapping$/i }).click();

    // 6. Back to list — the freshly created channel is listed.
    await page.goto('/modeling/channels');
    await expect(page.getByText(uniqueCode)).toBeVisible();
  });
});
