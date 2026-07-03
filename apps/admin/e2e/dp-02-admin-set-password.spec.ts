import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-02 (#2032) — admin sets a user's password from the panel.
 *
 * Single test, one form login. Creates a throwaway panel-managed account
 * via the browser-side API (email acts as a login identifier, no welcome
 * mail), resets its password through the new modal on the user detail
 * page, proves the new credential works with a direct API login, then
 * deactivates the account.
 */

async function browserApi(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async (args: { method: string; path: string; body?: unknown }) => {
      const refresh = await fetch('/api/auth/refresh', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { accept: 'application/json' },
      });
      const { token } = (await refresh.json()) as { token: string };
      const res = await fetch(args.path, {
        method: args.method,
        headers: {
          authorization: `Bearer ${token}`,
          accept: 'application/json',
          ...(args.body !== undefined ? { 'content-type': 'application/json' } : {}),
        },
        ...(args.body !== undefined ? { body: JSON.stringify(args.body) } : {}),
      });
      const text = await res.text();
      let parsed: unknown = null;
      try {
        parsed = text === '' ? null : JSON.parse(text);
      } catch {
        parsed = text;
      }
      return { status: res.status, body: parsed };
    },
    { method, path, body },
  );
}

test('DP-02 — admin resets a panel-managed user password via the modal', async ({ page }) => {
  await loginAsAdmin(page);

  const email = `dp02-e2e-${Date.now().toString(36)}@firma.local`;
  const created = await browserApi(page, 'POST', '/api/users', {
    email,
    role_code: 'catalog_manager',
    password: 'initial-secret-123', // gitleaks:allow — dummy e2e credential, account is deactivated in teardown
    force_password_change: false,
    send_welcome_email: false,
  });
  expect(created.status, JSON.stringify(created.body)).toBe(201);
  const userId = (created.body as { id: string }).id;

  try {
    await page.goto(`/settings/users/${userId}`);

    // Danger-zone row opens the modal.
    await page.getByRole('button', { name: /ustaw hasło|set password/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByLabel(/nowe hasło|new password/i).fill('reset-by-admin-456'); // gitleaks:allow — dummy e2e credential
    await dialog.getByRole('button', { name: /ustaw hasło|set password/i }).click();

    // Toast confirms; sessions revoked server-side.
    await expect(page.getByText(/hasło ustawione|password set/i).first()).toBeVisible();

    // The new credential really works (direct API login from the browser).
    const login = await page.evaluate(
      async (args: { email: string }) => {
        const res = await fetch('/api/auth/login', {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          // gitleaks:allow — dummy e2e credential, account deactivated in teardown
          body: JSON.stringify({ email: args.email, password: 'reset-by-admin-456' }),
        });
        return res.status;
      },
      { email },
    );
    expect(login).toBe(200);
  } finally {
    await browserApi(page, 'POST', `/api/users/${userId}/deactivate`);
  }
});
