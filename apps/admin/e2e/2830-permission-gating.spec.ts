import { expect, type Page, test } from '@playwright/test';

import { postJson } from './helpers/api';
import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * #2830 — the admin used to offer sections the caller had no permission
 * for. A Catalog Manager saw "Dodaj własny moduł" in the sidebar, walked
 * the four-step object-type wizard and met HTTP 403 only on save; the API
 * and XML configurators were listed for everyone too.
 *
 * The spec provisions a real Catalog Manager (invite → accept → sign in)
 * and asserts on what that role may see and reach, then re-checks the
 * admin so the gate cannot pass by hiding things from everybody.
 */

const MEMBER_PASSWORD = 'gating-e2e-password';

const modelingLink = (page: Page) => page.getByRole('link', { name: /^(Modelowanie|Modeling)$/ });
const customModuleLink = (page: Page) =>
  page.getByRole('link', { name: /(Dodaj własny moduł|Add custom module)/ });
const apiConfiguratorLink = (page: Page) =>
  page.getByRole('link', { name: /(Konfigurator API|API Configurator)/ });

async function provisionCatalogManager(page: Page): Promise<string> {
  const email = `e2e-gating-${Date.now().toString(36)}@example.com`;

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

  const accepted = await postJson(
    page,
    `/api/invitations/${invitation.body.token_dev_only}/accept`,
    { password: MEMBER_PASSWORD },
  );
  expect(accepted.status).toBe(201);

  await page.context().clearCookies();
  return email;
}

test('a role without modeling rights is neither offered nor let into it', async ({ page }) => {
  const email = await provisionCatalogManager(page);
  await loginAsAdmin(page, email, MEMBER_PASSWORD);

  // Sidebar: nothing that leads where the role cannot go.
  await expect(customModuleLink(page)).toHaveCount(0);
  await expect(modelingLink(page)).toHaveCount(0);

  // Integrations: the configurator is an admin surface (settings.integrations.manage).
  await expect(apiConfiguratorLink(page)).toHaveCount(0);

  // What the role DOES hold stays reachable — the gate must not blank the
  // app. Navigate in-app (a second full reload races the silent refresh and
  // can bounce the session to /login, which would mask the real assertion).
  // Prefix, not an exact match: the item carries a count badge fed by
  // GET /api/assets, and the badge is inside the link, so the accessible
  // name reads "Multimedia 10". Anchoring both ends passed only while the
  // role was refused that endpoint and the count stayed undefined — #2845
  // gave catalog roles their multimedia read, and this assertion was
  // measuring the 403, not the navigation.
  await page.getByRole('link', { name: /^Multimedia\b/ }).click();
  await expect(page).toHaveURL(/\/assets$/);

  // Deep link into the wizard: the 403 screen, not the form. This is the
  // exact URL the sidebar button used to hand out.
  await page.goto('/modeling/object-types/new');
  await expect(page.getByText(/(Brak dostępu|Access denied|403)/i).first()).toBeVisible();
  await expect(page.getByRole('button', { name: /(Utwórz typ|Create type)/ })).toHaveCount(0);
});

test('an admin still sees and reaches the modeling surface', async ({ page }) => {
  await loginAsAdmin(page);

  await expect(customModuleLink(page)).toBeVisible();

  await page.goto('/modeling/object-types/new');
  await expect(page.getByRole('button', { name: /(Utwórz typ|Create type)/ })).toBeVisible();
});
