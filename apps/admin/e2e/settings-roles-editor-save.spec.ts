import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * fix #2553 — the role EDITOR save flow had no e2e (only the list did), so a
 * save that failed with a non-JSON/HTML response surfaced a blanket
 * „Operacja na roli nie powiodła się" toast that hid the real cause. This spec
 * pins both halves of the fix in a single login (the auth rate-limiter is
 * shared across the RBAC suite):
 *
 *  1. Happy path — toggle a permission, save, expect `PATCH /api/roles/{id}`
 *     → 200 and the success toast.
 *  2. Resilience — when the PATCH returns a 200 HTML body (the FrankenPHP
 *     dev-cache-corruption shape), the toast surfaces the server's synthesized
 *     reason ("Server returned 200 with text/html …") instead of the generic
 *     message. The synthesized detail is emitted in English by lib/http.ts, so
 *     the assertion is locale-independent.
 */
test('Settings → Role editor — save surfaces real errors + happy path', async ({ page }) => {
  await loginAsAdmin(page);

  // Load the list and pull a real role id from its response (deterministic,
  // avoids brittle card-name locators). Prefer a custom role so the mutated
  // permission set does not touch a system template other specs may assert on.
  await page.goto('/settings/roles');
  const listResponse = await page.waitForResponse(
    (r) =>
      r.url().includes('/api/roles') &&
      r.request().method() === 'GET' &&
      !/\/api\/roles\/[0-9a-f-]{36}/.test(r.url()),
    { timeout: 30_000 },
  );
  const listJson = (await listResponse.json()) as {
    member?: Array<{ id: string; type?: string; code?: string }>;
    'hydra:member'?: Array<{ id: string; type?: string; code?: string }>;
  };
  const items = listJson.member ?? listJson['hydra:member'] ?? [];
  expect(items.length).toBeGreaterThan(0);
  // #2881 — the fallback must not land on a GLOBAL role. `super_admin` is
  // shared by every tenant and sorts first, so `items[0]` used to pick it
  // whenever the fixture had no custom role: this spec was quietly editing
  // a cross-tenant object, which the backend now refuses with 404. Skipping
  // the platform codes keeps the spec honest whatever the list contains.
  const platformCodes = new Set(['super_admin', 'platform_operator']);
  const editable = items.filter((r) => !platformCodes.has(r.code ?? ''));
  const target = editable.find((r) => r.type === 'custom') ?? editable[0];
  expect(target, 'the fixture must expose at least one tenant role').toBeDefined();

  // Deep-link into the editor; it hydrates from GET /api/roles/{id}.
  await page.goto(`/settings/roles/${target.id}/edit`);
  await page.waitForResponse(
    (r) => r.url().includes(`/api/roles/${target.id}`) && r.request().method() === 'GET',
    { timeout: 30_000 },
  );

  // Permission checkboxes carry aria-pressed; toggling one dirties the form.
  const firstPermission = page.locator('button[aria-pressed]').first();
  await firstPermission.waitFor({ state: 'visible', timeout: 15_000 });

  // --- 1. Happy path: PATCH round-trips 200 + success toast. ---
  await firstPermission.click();
  const patchOk = page.waitForResponse(
    (r) => r.url().includes(`/api/roles/${target.id}`) && r.request().method() === 'PATCH',
    { timeout: 30_000 },
  );
  await page.getByRole('button', { name: /zapisz zmiany|save changes/i }).click();
  expect((await patchOk).status()).toBe(200);
  await expect(page.getByText(/zapisano zmiany|saved changes/i)).toBeVisible({ timeout: 15_000 });

  // --- 2. Resilience: an HTML fatal on PATCH surfaces the real reason. ---
  await page.route(`**/api/roles/${target.id}`, async (route) => {
    if (route.request().method() === 'PATCH') {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<html><body>Fatal error: dev cache corruption</body></html>',
      });
      return;
    }
    await route.continue();
  });

  await page.locator('button[aria-pressed]').first().click();
  await page.getByRole('button', { name: /zapisz zmiany|save changes/i }).click();

  // The generic fallback must NOT swallow the cause — the synthesized detail
  // names the actual server response.
  await expect(
    page.getByText(/server returned 200 with text\/html|content-type when JSON was expected/i),
  ).toBeVisible({ timeout: 15_000 });
});
