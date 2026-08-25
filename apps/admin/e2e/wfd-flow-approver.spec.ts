import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * #3005 — the approver scenario, rewritten for the merged screen (#3000).
 * It used to drive "Ustawienia przepływu"; that page is gone, so the same
 * question — can an operator route review tasks to a chosen role and put
 * the flow live? — is answered against the definition editor.
 *
 * The spec configures the ASSET ObjectType on purpose: the other workflow
 * specs drive product/category submit→approve loops against the shared CI
 * database, so touching those here would leak an enabled definition into
 * them. Asset is an island; the definition is disabled again on teardown.
 */
test('flow editor: pick an approver, save, then activate the asset flow', async ({ page }) => {
  const login = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(login.status()).toBe(200);
  const { token } = (await login.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };

  const definitionName = `E2E approver ${Date.now().toString(36)}`;
  const assetTypeId = await assetObjectTypeId(page, bearer);

  try {
    await loginAsAdmin(page);
    await page.goto('/workflow');

    // #3000 — one entry point; the retired settings CTA is gone.
    await expect(page.getByTestId('workflow-settings-cta')).toHaveCount(0);
    await page.getByTestId('workflow-definitions-cta').click();
    await expect(page).toHaveURL(/\/workflow\/definitions$/);

    // #3004 — creating starts from a template, not an empty form.
    await page.getByTestId('definition-create').click();
    await expect(page.getByTestId('definition-template-picker')).toBeVisible();
    await page.getByTestId('definition-template-one_approval').click();
    await expect(page.getByTestId('workflow-definition-editor')).toBeVisible();

    await page.getByTestId('definition-name').fill(definitionName);

    // Scope to Asset so the shared CI database keeps product/category on
    // the built-in machine.
    const scope = page.getByTestId('workflow-definition-editor').locator('#definition-object-type');
    await scope.locator('button[aria-haspopup="listbox"]').click();
    await page.getByRole('button', { name: 'asset', exact: true }).click();

    // #3001 — the approver picker lives in the editor now. Pick the
    // tenant-unique Approver role (Catalog Manager exists both global and
    // per-tenant, which would trip strict mode).
    const picker = page.getByTestId('definition-reviewer');
    await picker.locator('button[aria-haspopup="listbox"]').click();
    await page
      .getByRole('button', { name: /^Approver \(rola\)$/i })
      .first()
      .click();

    // The non-technical path: a whole flow configured without typing a
    // single machine name — the advanced switch stays off and no
    // snake_case field is on screen.
    await expect(page.getByTestId('definition-advanced-toggle')).not.toBeChecked();
    await expect(page.getByTestId('place-name-0')).toHaveCount(0);
    // #3003 — the readiness list confirms the template flow holds together.
    await expect(page.getByTestId('definition-readiness')).toBeVisible();
    await expect(page.getByTestId('definition-readiness').getByRole('listitem')).toHaveCount(1);

    await page.getByTestId('definition-save').click();
    await expect(page.getByTestId('workflow-definitions-page')).toBeVisible();

    const row = page.getByTestId('definition-row').filter({ hasText: definitionName });
    await expect(row).toBeVisible();
    // #3004 — the row answers "who approves here" without opening it.
    await expect(row).toContainText(/Approver/i);
    // Saving does NOT activate: that decision is explicit now.
    await expect(row.getByText(/Wyłączona|Disabled/)).toBeVisible();

    await page.getByTestId(`definition-edit-${definitionName}`).click();
    await expect(page.getByTestId('workflow-definition-editor')).toBeVisible();

    // The picked approver survived the round-trip (the payload always
    // carries `reviewer`, so a draft that lost it would wipe the routing).
    await expect(page.getByTestId('definition-reviewer')).toContainText(/Approver/i);

    // Activation is a switch, confirmed because it governs live objects.
    await page.getByTestId('definition-enabled-toggle').check();
    await page.getByTestId('definition-confirm-save').click();
    await expect(page.getByTestId('definition-enabled-toggle')).toBeChecked();
  } finally {
    const list = await page.request.get('/api/workflow/definitions', { headers: bearer });
    if (list.ok()) {
      const body = (await list.json()) as {
        items: { id: string; name: string; object_type_id: string | null; enabled: boolean }[];
      };
      for (const definition of body.items) {
        const mine =
          definition.name === definitionName || definition.object_type_id === assetTypeId;
        if (mine && definition.enabled) {
          await page.request.post(`/api/workflow/definitions/${definition.id}/disable`, {
            headers: bearer,
          });
        }
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
  const types = body['hydra:member'] ?? body.member ?? [];
  const asset = types.find((candidate) => candidate.kind === 'asset');
  if (asset === undefined) throw new Error('No asset ObjectType seeded.');
  return asset.id;
}
