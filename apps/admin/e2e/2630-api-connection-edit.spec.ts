import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2630 — editing an existing connection reuses the SAME wizard: the Edit
 * action on the hub card opens `…/connections/{id}/edit` with the form
 * prefilled from GET (credentials stay blank — the API is write-only for
 * secrets), every step tab is immediately clickable, and saving step 1 sends
 * a PATCH without `code` and without `credentials` when none were typed.
 * The connection API is mocked so the flow is deterministic and offline.
 */
test('#2630 — edit connection opens the wizard prefilled and PATCHes without secrets', async ({
  page,
}) => {
  await loginAsAdmin(page);

  const connection = {
    id: 'conn-edit-1',
    code: 'nexar-components',
    name: 'Nexar Components',
    baseUrl: 'https://api.nexar.example/v2',
    authType: 'bearer',
    defaultHeaders: { Accept: 'application/json' },
    rateLimitHint: 300,
    status: 'active',
    lastHealthCheckAt: null,
    createdAt: '2026-07-01T10:00:00+00:00',
    updatedAt: '2026-07-01T10:00:00+00:00',
  };
  let patchBody: Record<string, unknown> | null = null;

  await page.route('**/api/connections?**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [connection], totalItems: 1 }),
    }),
  );
  await page.route('**/api/connections/conn-edit-1', (route: Route) => {
    if (route.request().method() === 'PATCH') {
      patchBody = route.request().postDataJSON() as Record<string, unknown>;
      return route.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify({ ...connection, ...patchBody }),
      });
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify(connection),
    });
  });
  // Steps 3–4 fetch sub-resources once a tab is opened; keep them empty.
  await page.route('**/api/remote_endpoints*', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [], totalItems: 0 }),
    }),
  );

  await page.goto('/integrations/api-configurator/connections');

  // The hub card exposes an Edit action above the stretched link.
  await page.getByRole('link', { name: /edytuj połączenie|edit connection/i }).click();
  await expect(page).toHaveURL(/\/connections\/conn-edit-1\/edit$/);

  // Prefill: identity fields carry the persisted values, the immutable code
  // input is disabled, the bearer secret stays blank (write-only API).
  const nameInput = page.getByRole('textbox', { name: /nazwa połączenia|connection name/i });
  await expect(nameInput).toHaveValue('Nexar Components');
  const codeInput = page.getByRole('textbox', { name: /^kod|^code/i });
  await expect(codeInput).toHaveValue('nexar-components');
  await expect(codeInput).toBeDisabled();

  // Every step tab is immediately clickable in edit mode.
  const stepButtons = page.locator('ol button');
  await expect(stepButtons).toHaveCount(4);
  for (let i = 0; i < 4; i += 1) {
    await expect(stepButtons.nth(i)).toBeEnabled();
  }

  // Change the name, then jump straight to the Endpoints tab — the wizard
  // persists step 1 first via PATCH.
  await nameInput.fill('Nexar Components EU');
  await stepButtons.nth(2).click();

  await expect
    .poll(() => patchBody, { message: 'PATCH /api/connections/{id} must fire on tab jump' })
    .not.toBeNull();
  expect(patchBody).toMatchObject({ name: 'Nexar Components EU' });
  expect(patchBody).not.toHaveProperty('code');
  expect(patchBody).not.toHaveProperty('credentials');

  // The jump landed on the Endpoints step.
  await expect(stepButtons.nth(2)).toHaveAttribute('aria-current', 'step');
});
