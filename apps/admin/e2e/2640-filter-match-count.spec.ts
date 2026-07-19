import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2640 — live filter match counters. The count must react to the panel's
 * DRAFT (before "Zastosuj filtr"): the WTP scope step counts products, the
 * API Configurator outbound-filter section counts the binding's objects.
 * Search API is mocked: without a `q` blob it reports 1000, with one — 82.
 */

async function mockSearch(page: import('@playwright/test').Page): Promise<void> {
  await page.route('**/api/search/products*', (route: Route) => {
    const url = new URL(route.request().url());
    const filtered = url.searchParams.get('q') !== null;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        hits: [],
        totalHits: filtered ? 82 : 1000,
        facetDistribution: {},
        processingTimeMs: 1,
        page: 1,
        perPage: 1,
      }),
    });
  });
}

test('#2640 — WTP scope count reacts to the draft before apply', async ({ page }) => {
  await loginAsAdmin(page);
  await mockSearch(page);

  await page.goto('/catalogs-pdf/new');
  await expect(page.getByTestId('catalog-scope-count')).toContainText('1000');

  // Add a condition and type a value — NO "Zastosuj filtr" click.
  await page.getByRole('button', { name: /dodaj warunek|add condition/i }).click();
  await page.getByPlaceholder(/wpisz wartość|enter value/i).fill('Sony');

  await expect(page.getByTestId('catalog-scope-count')).toContainText('82');
});

test('#2640 — APIC outbound filter shows a live match count', async ({ page }) => {
  await loginAsAdmin(page);
  await mockSearch(page);

  const binding = {
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
  await page.route('**/api/sync_bindings**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [binding], totalItems: 1 }),
    }),
  );
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
            responseIdSelector: null,
            responseIdAttribute: null,
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
      body: JSON.stringify({
        member: [{ id: 'ot-1', code: 'product', kind: 'product' }],
        totalItems: 1,
      }),
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
  await expect(page.getByTestId('outbound-filter-match-count')).toContainText('1000');

  // Draft a condition — the counter narrows live, before apply.
  await page.getByTestId('outbound-filter-toggle').click();
  await page.getByRole('button', { name: /dodaj warunek|add condition/i }).click();
  await page
    .getByTestId('outbound-filter-section')
    .getByPlaceholder(/wpisz wartość|enter value/i)
    .fill('ACME');
  await expect(page.getByTestId('outbound-filter-match-count')).toContainText('82');
});
