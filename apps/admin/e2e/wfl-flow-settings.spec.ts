import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * WFL redesign (#2515) — the "Ustawienia przepływu" page, reached from
 * the Workflow hub CTA. Picking an approver and saving creates + enables
 * a tenant definition for the selected ObjectType (the "własny" badge
 * confirms it). Requires WORKFLOW_CUSTOM_DEFINITIONS=true in CI.
 *
 * The test configures the ASSET ObjectType on purpose: the other
 * workflow specs drive product/category submit→approve loops against the
 * shared CI database, so touching product/category here would leak an
 * enabled definition (with a completeness gate) into them. Asset is an
 * island; the created definition is disabled again on teardown.
 */
test('flow settings: pick an approver and save enables the asset definition', async ({ page }) => {
  const login = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(login.status()).toBe(200);
  const { token } = (await login.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };

  const assetTypeId = await assetObjectTypeId(page, bearer);

  try {
    await loginAsAdmin(page);
    await page.goto('/workflow');

    // #2574 — the hub exposes both CTAs to a manager: custom definitions and
    // the flow-settings page. The definitions CTA points at the moved route.
    await expect(page.getByTestId('workflow-definitions-cta')).toHaveAttribute(
      'href',
      '/workflow/definitions',
    );

    await page.getByTestId('workflow-settings-cta').click();
    await expect(page).toHaveURL(/\/workflow\/settings$/);
    await expect(page.getByTestId('workflow-settings-page')).toBeVisible();
    await expect(page.getByTestId('workflow-role-legend')).toBeVisible();
    await expect(page.getByTestId('workflow-state-diagram')).toBeVisible();

    // Switch to the asset definition and pick the tenant-unique Approver
    // role (Catalog Manager exists both global and per-tenant → strict mode).
    await page.getByTestId('workflow-def-type-asset').click();
    const picker = page.getByTestId('workflow-approver-picker');
    await picker.getByRole('button').first().click();
    await page
      .getByRole('button', { name: /^Approver \(rola\)$/i })
      .first()
      .click();

    await page.getByTestId('workflow-settings-save').click();

    // The asset type pill now carries the "własny" badge (enabled def).
    await expect(page.getByTestId('workflow-def-type-asset').getByText(/własny/i)).toBeVisible();

    // Persisted: reloading keeps the approver selected.
    await page.reload();
    await page.getByTestId('workflow-def-type-asset').click();
    await expect(page.getByTestId('workflow-approver-picker')).toContainText(/Approver/i);
  } finally {
    // Teardown: disable any asset definition we enabled so parallel/later
    // specs see the static machine again.
    const list = await page.request.get('/api/workflow/definitions', { headers: bearer });
    const body = (await list.json()) as {
      items: { id: string; object_type_id: string | null; enabled: boolean }[];
    };
    for (const def of body.items) {
      if (def.object_type_id === assetTypeId && def.enabled) {
        await page.request.post(`/api/workflow/definitions/${def.id}/disable`, { headers: bearer });
      }
    }
  }
});

async function assetObjectTypeId(
  page: import('@playwright/test').Page,
  bearer: Record<string, string>,
): Promise<string> {
  const response = await page.request.get('/api/object_types?itemsPerPage=200', {
    headers: { ...bearer, accept: 'application/ld+json' },
  });
  const body = (await response.json()) as {
    'hydra:member'?: { id: string; kind: string }[];
    member?: { id: string; kind: string }[];
  };
  const asset = (body['hydra:member'] ?? body.member ?? []).find((type) => type.kind === 'asset');
  if (asset === undefined) throw new Error('No asset ObjectType seeded.');
  return asset.id;
}
