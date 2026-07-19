import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2636 — remote-id capture config in the wizard: a write endpoint carries a
 * response-id JSONPath + the PIM attribute the captured id is stored in, so
 * subsequent pushes become updates instead of duplicates. Descriptor API is
 * mocked (offline).
 */
test('#2636 — wizard write endpoint with remote-id capture fields', async ({ page }) => {
  await loginAsAdmin(page);

  const endpoints: Array<Record<string, unknown>> = [];
  let lastCreatePayload: Record<string, unknown> | null = null;

  await page.route('**/api/connections', (route: Route) => {
    if (route.request().method() === 'POST') {
      return route.fulfill({
        status: 201,
        contentType: 'application/ld+json',
        body: JSON.stringify({ id: 'conn-1', code: 'base', name: 'Base', status: 'draft' }),
      });
    }
    return route.fallback();
  });

  await page.route('**/api/remote_endpoints*', (route: Route) => {
    if (route.request().method() === 'POST') {
      const body = route.request().postDataJSON() as Record<string, unknown>;
      lastCreatePayload = body;
      const created = {
        id: `ep-${endpoints.length + 1}`,
        connectionId: 'conn-1',
        role: body.role ?? 'read_list',
        httpMethod: body.httpMethod ?? 'GET',
        pathTemplate: body.pathTemplate ?? '/',
        pagination: body.pagination ?? { strategy: 'none' },
        recordSelector: body.recordSelector ?? null,
        requestFormat: body.requestFormat ?? 'json',
        requestBodyTemplate: body.requestBodyTemplate ?? null,
        responseIdSelector: body.responseIdSelector ?? null,
        responseIdAttribute: body.responseIdAttribute ?? null,
      };
      endpoints.push(created);
      return route.fulfill({
        status: 201,
        contentType: 'application/ld+json',
        body: JSON.stringify(created),
      });
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: endpoints, totalItems: endpoints.length }),
    });
  });

  await page.goto('/integrations/api-configurator/connections/new');
  await page.getByLabel(/nazwa połączenia|connection name/i).fill('Base');
  await page.getByLabel(/^base url$/i).fill('https://api.baselinker.com');
  await page.getByRole('button', { name: /dalej|next/i }).click();
  await expect(
    page.getByRole('button', { name: /testuj połączenie|test connection/i }),
  ).toBeVisible();
  await page.getByRole('button', { name: /dalej|next/i }).click();
  await expect(page.getByRole('button', { name: /dodaj endpoint|add endpoint/i })).toBeVisible();

  // Read roles: no capture inputs.
  await expect(page.getByLabel(/selector id z odpowiedzi|response id selector/i)).toHaveCount(0);

  // Write role: capture inputs appear and travel in the create payload.
  await page.getByRole('button', { name: 'write_create', exact: true }).click();
  await page.getByRole('button', { name: 'POST', exact: true }).click();
  await page.getByLabel(/^ścieżka$|^path$/i).fill('/connector.php');
  await page.getByLabel(/selector id z odpowiedzi|response id selector/i).fill('$.product_id');
  await page.getByLabel(/zapisz id do atrybutu|store id in attribute/i).fill('base_product_id');
  await page.getByRole('button', { name: /dodaj endpoint|add endpoint/i }).click();

  await expect(page.getByText('/connector.php')).toBeVisible();
  await expect(page.getByText(/json · ID/)).toBeVisible();
  expect(lastCreatePayload).toMatchObject({
    role: 'write_create',
    responseIdSelector: '$.product_id',
    responseIdAttribute: 'base_product_id',
  });
});
