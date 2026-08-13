import { expect, type Page, test } from '@playwright/test';

import { postJson } from './helpers/api';
import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * #2831 — the dashboard sat behind a single `products.view` gate and then
 * showed everything it had: per-channel completeness (channel data) and
 * "Tempo pracy zespołu", which attributes edits to named people. A Catalog
 * Manager holds `products.view` and neither `channel.read` nor
 * `audit.view_cross_user`, so it was reading both.
 */

const MEMBER_PASSWORD = 'dashboard-e2e-password';

const channelSection = (page: Page) =>
  page.getByText(/(Kompletność wg kanału|Completeness by channel)/);
const teamActivityCard = (page: Page) => page.getByText(/(Tempo pracy zespołu|Team pace)/);

test('a catalog role sees the dashboard without channel or cross-user data', async ({ page }) => {
  const email = `e2e-dash-${Date.now().toString(36)}@example.com`;

  await page.goto('/login');
  const login = await postJson<{ token: string }>(page, '/api/auth/login', {
    email: ADMIN_EMAIL,
    password: ADMIN_PASSWORD,
  });
  expect(login.status).toBe(200);

  const invitation = await postJson<{ token_dev_only: string }>(
    page,
    '/api/invitations',
    { email, role_code: 'catalog_manager' },
    { authorization: `Bearer ${login.body.token}` },
  );
  expect(invitation.status).toBe(201);
  expect(
    (
      await postJson(page, `/api/invitations/${invitation.body.token_dev_only}/accept`, {
        password: MEMBER_PASSWORD,
      })
    ).status,
  ).toBe(201);

  // Grab this member's own JWT for the raw API assertions below: the SPA
  // keeps its access token in module memory (never a cookie), so a bare
  // fetch from the page would go out unauthenticated.
  const memberLogin = await postJson<{ token: string }>(page, '/api/auth/login', {
    email,
    password: MEMBER_PASSWORD,
  });
  expect(memberLogin.status).toBe(200);
  const memberJwt = memberLogin.body.token;

  await page.context().clearCookies();
  await loginAsAdmin(page, email, MEMBER_PASSWORD);

  // The KPIs the role IS entitled to stay — this must narrow the dashboard,
  // not empty it.
  await expect(page.getByText(/(Kompletność katalogu|Catalog completeness)/)).toBeVisible();

  await expect(channelSection(page)).toHaveCount(0);
  await expect(teamActivityCard(page)).toHaveCount(0);

  // The payload itself is trimmed — hiding the tile while the API still
  // ships the rows would leave the data one devtools tab away.
  const summary = await page.evaluate(async (jwt) => {
    const response = await fetch('/api/dashboard/summary', {
      headers: { accept: 'application/json', authorization: `Bearer ${jwt}` },
    });
    return { status: response.status, body: await response.json() };
  }, memberJwt);
  expect(summary.status).toBe(200);
  expect(summary.body.channels).toEqual([]);

  const topEdited = await page.evaluate(async (jwt) => {
    const response = await fetch('/api/dashboard/top-edited', {
      headers: { accept: 'application/json', authorization: `Bearer ${jwt}` },
    });
    return response.status;
  }, memberJwt);
  expect(topEdited).toBe(403);
});

test('an admin keeps the full dashboard', async ({ page }) => {
  await loginAsAdmin(page);

  await expect(channelSection(page)).toBeVisible();
  await expect(teamActivityCard(page)).toBeVisible();
});
