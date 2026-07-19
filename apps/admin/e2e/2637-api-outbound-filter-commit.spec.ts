import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2637 — regression: applying the outbound filter must reach the parent DSL
 * and persist. The panel's apply fires setConditions + setMatchOperator
 * back-to-back; the second commit used to read a stale (empty) conditions
 * closure and wipe the DSL, so the summary stayed "Bez filtra" and the binding
 * PATCH carried an empty outboundFilter. Binding CRUD is mocked (stateful),
 * offline and deterministic.
 */
test('#2637 — outbound filter: apply persists conditions into the binding PATCH', async ({
  page,
}) => {
  await loginAsAdmin(page);

  const binding: Record<string, unknown> = {
    id: 'bind-1',
    connectionId: 'conn-1',
    objectTypeId: 'ot-1',
    readEndpointId: null,
    writeEndpointId: 'ep-write',
    direction: 'outbound',
    schedule: null,
    conflictPolicy: 'lww',
    matchKeyMapping: null,
    cursor: null,
    isEnabled: true,
    outboundFilter: null,
  };
  const patchedFilters: unknown[] = [];

  await page.route('**/api/sync_bindings**', (route: Route) => {
    if (route.request().method() === 'PATCH') {
      const body = route.request().postDataJSON() as Record<string, unknown>;
      patchedFilters.push(body.outboundFilter);
      Object.assign(binding, body);
      return route.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify(binding),
      });
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [binding], totalItems: 1 }),
    });
  });

  await page.route('**/api/remote_endpoints**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [
          {
            id: 'ep-write',
            connectionId: 'conn-1',
            role: 'write_create',
            httpMethod: 'POST',
            pathTemplate: '/connector.php',
            pagination: { strategy: 'none' },
            recordSelector: null,
            requestFormat: 'form',
            requestBodyTemplate: null,
          },
        ],
        totalItems: 1,
      }),
    }),
  );

  await page.route('**/api/object_types**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [{ id: 'ot-1', code: 'product' }], totalItems: 1 }),
    }),
  );

  await page.route('**/api/attributes**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [
          { id: 'attr-1', code: 'brand', label: { pl: 'Marka' }, type: 'text', filterable: true },
        ],
        totalItems: 1,
      }),
    }),
  );

  await page.goto('/integrations/api-configurator/connections/conn-1/sync');
  await expect(
    page.getByRole('heading', {
      name: /konfiguracja synchronizacji|synchronization configuration/i,
    }),
  ).toBeVisible();

  const section = page.getByTestId('outbound-filter-section');
  await expect(section).toBeVisible();
  await expect(page.getByTestId('outbound-filter-summary')).toContainText(/bez filtra|no filter/i);

  // Open the panel, add a condition and apply.
  await page.getByTestId('outbound-filter-toggle').click();
  await page.getByRole('button', { name: /dodaj warunek|add condition/i }).click();
  await section.getByPlaceholder(/wpisz wartość|enter value/i).fill('ACME');
  await page.getByRole('button', { name: /zastosuj|apply/i }).click();

  // The bug: the summary stayed "Bez filtra" because the second commit wiped
  // the DSL. Post-fix the applied condition must be reflected…
  await expect(page.getByTestId('outbound-filter-summary')).toContainText(/1 warun/i);

  // …and persisted into the binding PATCH as a non-empty outboundFilter.
  await page.getByRole('button', { name: /zapisz wiązanie|save binding/i }).click();
  await expect.poll(() => patchedFilters.length).toBeGreaterThan(0);
  expect(patchedFilters[0]).toMatchObject({ attr: 'brand', op: '=', value: 'ACME' });

  // Reload — the stored filter hydrates back into the summary.
  await page.reload();
  await expect(page.getByTestId('outbound-filter-summary')).toContainText(/1 warun/i);
});
