import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { postJson } from './helpers/api';
import { ADMIN_EMAIL, ADMIN_PASSWORD } from './helpers/auth';

/**
 * #2827 — the invitation e-mail used to link at `/invitations/{token}/accept`,
 * a path no router served: Caddy hands every non-/api path to the SPA, whose
 * catch-all sent the invitee to /dashboard and, with no session, on to /login.
 * The invitee saw a bare login screen and the single-use token was burnt.
 *
 * The API-level tests never caught it because they POST to the endpoints
 * directly. This spec walks the road the invitee actually walks: mint an
 * invitation, open the URL shape the mail now carries, set a password, land
 * in the app.
 */

const INVITEE_PASSWORD = 'invitee-e2e-password';

function uniqueEmail(): string {
  return `e2e-invite-${Date.now().toString(36)}@example.com`;
}

test('invitation link opens the accept page and onboards the user', async ({ page }) => {
  const email = uniqueEmail();

  // Mint the invitation as admin, then drop the admin session — the invitee
  // arrives with no cookies at all, which is the case that used to break.
  await page.goto('/login');
  const login = await postJson<{ token: string }>(page, '/api/auth/login', {
    email: ADMIN_EMAIL,
    password: ADMIN_PASSWORD,
  });
  expect(login.status).toBe(200);

  const invitation = await postJson<{ token_dev_only: string }>(
    page,
    '/api/invitations',
    { email, role_code: 'viewer' },
    { authorization: `Bearer ${login.body.token}` },
  );
  expect(invitation.status).toBe(201);
  // `token_dev_only` is the plaintext the mail carries; dev/test only.
  const token = invitation.body.token_dev_only;
  expect(token).toMatch(/^[a-f0-9]{64}$/);

  await page.context().clearCookies();

  // This is the exact URL shape InvitationService now puts in the mail.
  await page.goto(`/accept-invitation?token=${token}`);

  await expect(page.getByText(email)).toBeVisible();
  const passwordField = page.getByLabel(/^(Nowe hasło|New password)/);
  await expect(passwordField).toBeVisible();

  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);

  await passwordField.fill(INVITEE_PASSWORD);
  await page.getByLabel(/^(Powtórz hasło|Confirm password)$/).fill(INVITEE_PASSWORD);
  await page
    .getByRole('button', { name: /(Aktywuj konto i zaloguj|Activate account and sign in)/ })
    .click();

  // Accept + auto-login: the invitee ends up inside the app, not on /login.
  await expect(page).toHaveURL(/\/dashboard$/, { timeout: 15_000 });
});
