import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { postJson } from './helpers/api';
import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * #2827 — before this ticket the reset mail linked at `/password-reset/{token}`
 * and there was no reset UI at all, so the whole self-service recovery path
 * was unreachable: the link dropped the user on /login and nothing on /login
 * offered a way in.
 *
 * The spec drives the complete road: ask for a link from the sign-in screen,
 * open the URL shape the mail now carries, set a new password, sign in with
 * it, and confirm the spent token cannot be replayed.
 *
 * The reset target is a throwaway account minted through an invitation — the
 * spec must not change the shared admin password other specs sign in with.
 */

const FIRST_PASSWORD = 'reset-e2e-first-pass';
const SECOND_PASSWORD = 'reset-e2e-second-pass';

test('the reset link sets a new password and cannot be replayed', async ({ page }) => {
  const email = `e2e-reset-${Date.now().toString(36)}@example.com`;

  // --- arrange: a real account to recover ------------------------------
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

  const accepted = await postJson(
    page,
    `/api/invitations/${invitation.body.token_dev_only}/accept`,
    { password: FIRST_PASSWORD },
  );
  expect(accepted.status).toBe(201);

  await page.context().clearCookies();

  // --- act: request a reset from the sign-in screen ---------------------
  await page.goto('/login');
  await page.getByRole('link', { name: /(Nie pamiętam hasła|Forgot your password)/ }).click();
  await expect(page).toHaveURL(/\/forgot-password$/);

  const forgotA11y = await new AxeBuilder({ page }).analyze();
  expect(forgotA11y.violations).toEqual([]);

  await page.getByLabel('E-mail').fill(email);
  const requestResponse = page.waitForResponse(
    (response) =>
      response.url().includes('/api/auth/password-reset/request') &&
      response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: /(Wyślij link|Send link)/ }).click();

  // The plaintext token is what the mail carries; dev/test expose it in the
  // response so the spec can follow the link without a mailbox.
  const { token_dev_only: resetToken } = (await (await requestResponse).json()) as {
    token_dev_only: string;
  };
  expect(resetToken).toMatch(/^[a-f0-9]{64}$/);
  await expect(page.getByText(/(Sprawdź skrzynkę|Check your inbox)/)).toBeVisible();

  // --- act: follow the link the mail now carries ------------------------
  await page.goto(`/password-reset?token=${resetToken}`);
  const newPassword = page.getByLabel(/^(Nowe hasło|New password)$/);
  await expect(newPassword).toBeVisible();

  const resetA11y = await new AxeBuilder({ page }).analyze();
  expect(resetA11y.violations).toEqual([]);

  await newPassword.fill(SECOND_PASSWORD);
  await page.getByLabel(/^(Powtórz hasło|Repeat password)$/).fill(SECOND_PASSWORD);
  await page.getByRole('button', { name: /(Zapisz hasło|Save password)/ }).click();
  await expect(page).toHaveURL(/\/login$/);

  // --- assert: the new password works -----------------------------------
  await loginAsAdmin(page, email, SECOND_PASSWORD);

  // --- assert: the spent token is refused -------------------------------
  await page.context().clearCookies();
  await page.goto(`/password-reset?token=${resetToken}`);
  await page.getByLabel(/^(Nowe hasło|New password)$/).fill('third-e2e-password-x');
  await page.getByLabel(/^(Powtórz hasło|Repeat password)$/).fill('third-e2e-password-x');
  await page.getByRole('button', { name: /(Zapisz hasło|Save password)/ }).click();
  await expect(
    page.getByText(/(Link jest nieaktualny|This link is no longer valid)/),
  ).toBeVisible();
});
