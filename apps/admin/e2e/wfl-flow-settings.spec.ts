import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * WFL redesign (#2515) — the "Ustawienia przepływu" page, reached from
 * the Workflow hub CTA. Picking an approver and saving creates + enables
 * a tenant definition for the selected ObjectType (the "własny" badge
 * confirms it). Requires WORKFLOW_CUSTOM_DEFINITIONS=true in CI (same as
 * the P5-03 builder spec).
 */
test('flow settings: pick an approver and save enables the definition', async ({ page }) => {
  // Clean slate: drop any pre-existing definitions so the type pill has no
  // "własny" badge before we save (a Bearer for the setup calls).
  const login = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(login.status()).toBe(200);
  const { token } = (await login.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };
  const existing = await page.request.get('/api/workflow/definitions', { headers: bearer });
  const list = (await existing.json()) as { items: { id: string; enabled: boolean }[] };
  for (const def of list.items) {
    if (def.enabled) {
      await page.request.post(`/api/workflow/definitions/${def.id}/disable`, { headers: bearer });
    }
  }

  await loginAsAdmin(page);
  await page.goto('/workflow');

  await page.getByTestId('workflow-settings-cta').click();
  await expect(page).toHaveURL(/\/workflow\/settings$/);
  await expect(page.getByTestId('workflow-settings-page')).toBeVisible();
  await expect(page.getByTestId('workflow-role-legend')).toBeVisible();
  await expect(page.getByTestId('workflow-state-diagram')).toBeVisible();

  // Product is the default selected type; pick a role approver. The
  // combobox renders options as buttons behind a search input; narrow to
  // the tenant "Approver" role (unique, unlike Catalog Manager which
  // exists both global and per-tenant).
  const picker = page.getByTestId('workflow-approver-picker');
  await picker.getByRole('button').first().click();
  await page
    .getByRole('button', { name: /^Approver \(rola\)$/i })
    .first()
    .click();

  await page.getByTestId('workflow-settings-save').click();

  // The product type pill now carries the "własny" badge (enabled def).
  await expect(page.getByTestId('workflow-def-type-product').getByText(/własny/i)).toBeVisible();

  // Persisted: reloading keeps the approver selected.
  await page.reload();
  await expect(page.getByTestId('workflow-approver-picker')).toContainText(/Approver/i);
});
