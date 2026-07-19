import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2634 — RPC-style write endpoints in the wizard: a write endpoint can pick
 * the `form` body format and carry a static JSON body template (BaseLinker's
 * method/parameters envelope). Invalid template JSON blocks the add with an
 * inline error. The descriptor API is mocked so the test is offline.
 */
test('#2634 — wizard write endpoint with form format + body template', async ({ page }) => {
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
    const method = route.request().method();
    if (method === 'POST') {
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

  // Step 1 → persist draft → step 2 → step 3 (endpoints).
  await page.getByLabel(/nazwa połączenia|connection name/i).fill('Base');
  await page.getByLabel(/^base url$/i).fill('https://api.baselinker.com');
  await page.getByRole('button', { name: /dalej|next/i }).click();
  await expect(
    page.getByRole('button', { name: /testuj połączenie|test connection/i }),
  ).toBeVisible();
  await page.getByRole('button', { name: /dalej|next/i }).click();
  await expect(page.getByRole('button', { name: /dodaj endpoint|add endpoint/i })).toBeVisible();

  // Read roles show no body-template controls.
  await expect(page.getByLabel(/szablon body|body template/i)).toHaveCount(0);

  // Switch to a write role — the body format + template controls appear.
  await page.getByRole('button', { name: 'write_create', exact: true }).click();
  await page.getByRole('button', { name: 'POST', exact: true }).click();
  await page.getByLabel(/^ścieżka$|^path$/i).fill('/connector.php');
  await page.getByRole('button', { name: 'form', exact: true }).click();

  // Invalid template JSON blocks the add with an inline error.
  await page.getByLabel(/szablon body|body template/i).fill('{not json');
  await page.getByRole('button', { name: /dodaj endpoint|add endpoint/i }).click();
  await expect(page.getByRole('alert')).toContainText(/poprawnym obiektem json|valid json object/i);

  // Valid template creates the endpoint with format + template in the payload.
  await page
    .getByLabel(/szablon body|body template/i)
    .fill('{"method":"addInventoryProduct","parameters":{"inventory_id":1234}}');
  await page.getByRole('button', { name: /dodaj endpoint|add endpoint/i }).click();

  await expect(page.getByText('/connector.php')).toBeVisible();
  await expect(page.getByText(/form · (szablon|template)/)).toBeVisible();
  expect(lastCreatePayload).toMatchObject({
    role: 'write_create',
    httpMethod: 'POST',
    pathTemplate: '/connector.php',
    requestFormat: 'form',
    requestBodyTemplate: { method: 'addInventoryProduct', parameters: { inventory_id: 1234 } },
  });

  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);
});
