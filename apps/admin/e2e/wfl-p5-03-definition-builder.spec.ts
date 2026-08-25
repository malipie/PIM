import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, apiLogin, uniqueSku } from './helpers/auth';

/**
 * WFL-P5-03 (#2433) — the definition builder full cycle: create a custom
 * flow with a `translation` stage, enable it, and see the new transition
 * surface on a fresh product — no deploy.
 *
 * #3002 — driven through the rewritten editor: the operator types stage
 * LABELS and the machine name is derived from them, so raw names are
 * reachable only behind the advanced switch, and the from/to pickers list
 * labels rather than machine names.
 * The CI stack runs WORKFLOW_CUSTOM_DEFINITIONS=true; the definition is
 * disabled again in cleanup so the rest of the suite keeps the static
 * machine (with no enabled definition the provider falls back to YAML).
 */
test('definition builder: custom place shows up on the product without a deploy', async ({
  page,
}) => {
  const loginResponse = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(loginResponse.status()).toBe(200);
  const { token } = (await loginResponse.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };

  await apiLogin(page);

  const definitionName = `E2E builder ${Date.now().toString(36)}`;

  try {
    // #2574 — the definition builder moved out of Settings into the Workflow
    // hub. The old /settings/workflow path still redirects here.
    await page.goto('/workflow/definitions');
    await expect(page.getByTestId('workflow-definitions-page')).toBeVisible();

    // Accessibility gate on the list page (serious/critical only, per DoD).
    // `.exclude('.bg-cta')` — the orange primary CTA (#2677) is the sanctioned
    // design accent whose white-on-#ff4f00 contrast (3.29) is excluded across
    // a11y specs (same as nui-13 / exr-16 / feed wizard).
    const a11y = await new AxeBuilder({ page })
      .include('[data-testid="workflow-definitions-page"]')
      .exclude('.bg-cta')
      .analyze();
    expect(
      a11y.violations.filter(
        (violation) => violation.impact === 'serious' || violation.impact === 'critical',
      ),
    ).toEqual([]);

    await page.getByTestId('definition-create').click();
    await expect(page.getByTestId('workflow-definition-editor')).toBeVisible();

    await page.getByTestId('definition-name').fill(definitionName);

    // Raw machine names are advanced-only now — that is where the
    // non-snake_case edge case still lives.
    await page.getByTestId('definition-advanced-toggle').check();

    // Edge case: a non-snake_case place name surfaces an inline error.
    await page.getByTestId('place-add').click();
    await page.getByTestId('place-name-2').fill('Translation!');
    await page.getByTestId('definition-save').click();
    await expect(page.getByRole('alert').first()).toBeVisible();

    // Fix the name, wire the transition draft -> translation.
    await page.getByTestId('place-name-2').fill('translation');
    await page.getByTestId('transition-add').click();
    await page.getByTestId('transition-label-1').fill('to_translation');
    // From: multi-select 'draft' (options render as buttons inside the
    // component root); To: combobox 'translation'.
    const fromSelect = page.getByTestId('transition-from-1');
    await fromSelect.locator('[role="button"]').click();
    // The pickers speak labels now: the starter draft's `draft` place is
    // labelled "Szkic"; `translation` has no label, so it falls back to
    // its machine name.
    await fromSelect.getByRole('button', { name: 'Szkic', exact: true }).click();
    // Clicking the To trigger fires the outside-mousedown that closes the
    // still-open MultiSelect dropdown.
    const toSelect = page.getByTestId('transition-to-1');
    await toSelect.locator('button[aria-haspopup="listbox"]').click();
    await toSelect.getByRole('button', { name: 'translation', exact: true }).click();

    await page.getByTestId('definition-save').click();
    await expect(page.getByTestId('workflow-definitions-page')).toBeVisible();
    await expect(
      page.getByTestId('definition-row').filter({ hasText: definitionName }),
    ).toBeVisible();

    // Enable it — from now the custom machine governs the tenant.
    await page.getByTestId(`definition-toggle-${definitionName}`).click();
    await expect(
      page
        .getByTestId('definition-row')
        .filter({ hasText: definitionName })
        .getByText(/Włączona|Enabled/),
    ).toBeVisible();

    // Fresh product now discovers the custom transition — no deploy.
    const typesResponse = await page.request.get('/api/object_types?itemsPerPage=200', {
      headers: { ...bearer, accept: 'application/ld+json' },
    });
    const types = (await typesResponse.json()) as {
      member?: { id: string; kind: string }[];
    };
    const productType = (types.member ?? []).find((candidate) => candidate.kind === 'product');
    if (productType === undefined) throw new Error('No product ObjectType seeded.');

    const createResponse = await page.request.post('/api/objects', {
      data: { code: uniqueSku('WFL-BLD'), objectTypeId: productType.id },
      headers: { ...bearer, 'content-type': 'application/ld+json' },
    });
    expect(createResponse.status()).toBe(201);
    const created = (await createResponse.json()) as { id: string };

    await page.goto(`/products/${created.id}`);
    await expect(page.getByTestId('workflow-status-control')).toBeVisible();
    // #2529 — transitions live in the Workflow modal (Administrator tab shows
    // the full set, including this custom machine's renamed transitions).
    await page.getByTestId('workflow-open').click();
    await expect(page.getByTestId('workflow-modal')).toBeVisible();
    await expect(page.getByTestId('workflow-transition-to_translation')).toBeVisible();
    // The static machine's submit button is gone under the custom machine.
    await expect(page.getByTestId('workflow-transition-submit_for_review')).toHaveCount(0);
  } finally {
    // Disable whatever this spec enabled so the rest of the suite runs
    // against the static YAML machine again.
    const listResponse = await page.request.get('/api/workflow/definitions', {
      headers: bearer,
    });
    if (listResponse.ok()) {
      const list = (await listResponse.json()) as {
        items: { id: string; name: string; enabled: boolean }[];
      };
      for (const definition of list.items) {
        if (definition.name === definitionName && definition.enabled) {
          await page.request.post(`/api/workflow/definitions/${definition.id}/disable`, {
            headers: bearer,
          });
        }
      }
    }
  }
});
