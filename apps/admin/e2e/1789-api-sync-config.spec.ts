import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * APIC-P3-11 (#1789) — the SyncBinding configuration screen. The binding CRUD +
 * run action are mocked (stateful), so the test is deterministic and offline:
 * switch direction (conditional panels), save, and run-now.
 */
test('APIC-P3-11 — sync config: direction panels + save + run-now', async ({ page }) => {
  await loginAsAdmin(page);

  const binding: Record<string, unknown> = {
    id: 'bind-1',
    connectionId: 'conn-1',
    objectTypeId: 'ot-1',
    readEndpointId: null,
    writeEndpointId: null,
    direction: 'inbound',
    schedule: '0 2 * * *',
    conflictPolicy: 'lww',
    matchKeyMapping: 'sku',
    cursor: { field: 'updated_at', type: 'updated_at', state: '2026-05-11T13:58:22Z' },
    isEnabled: true,
    nextRun: '2026-12-01T02:00:00+00:00',
    sourceChannel: null,
    sourceLocale: null,
  };
  let patched = false;
  let lastPatch: Record<string, unknown> = {};
  let ran = false;

  await page.route('**/api/sync_bindings**', (route: Route) => {
    const url = route.request().url();
    const method = route.request().method();

    if (method === 'POST' && url.includes('/run')) {
      ran = true;
      return route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ dispatched: true, direction: binding.direction, next_run: null }),
      });
    }

    if (method === 'PATCH') {
      patched = true;
      const body = route.request().postDataJSON() as Record<string, unknown>;
      lastPatch = body;
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
            id: 'ep-read',
            connectionId: 'conn-1',
            role: 'read_list',
            httpMethod: 'GET',
            pathTemplate: '/products',
            pagination: { strategy: 'none' },
            recordSelector: '$.results',
          },
          {
            id: 'ep-write',
            connectionId: 'conn-1',
            role: 'write_update',
            httpMethod: 'PUT',
            pathTemplate: '/products/{id}',
            pagination: { strategy: 'none' },
            recordSelector: null,
          },
        ],
        totalItems: 2,
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

  // #2667 — the value-source section pulls channels + workspace locales.
  await page.route('**/api/channels**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [{ id: 'ch-1', code: 'baselinker', name: 'BaseLinker' }],
        totalItems: 1,
      }),
    }),
  );
  await page.route('**/api/workspaces/current**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: 'ws-1',
        code: 'demo',
        name: 'Demo',
        plan: 'mvp',
        enabledLocales: ['pl', 'en'],
        primaryLocale: 'pl',
      }),
    }),
  );

  await page.goto('/integrations/api-configurator/connections/conn-1/sync');

  await expect(
    page.getByRole('heading', {
      name: /konfiguracja synchronizacji|synchronization configuration/i,
    }),
  ).toBeVisible();

  // Inbound: cursor card present, conflict card absent. Target the conflict
  // card's unique Last-write-wins option (the section title phrase also appears
  // in the page subtitle, so it is not a reliable presence signal).
  await expect(page.getByText(/cursor \(inkrementalny|cursor \(incremental/i)).toBeVisible();
  await expect(page.getByRole('button', { name: /last-write-wins/i })).toHaveCount(0);
  // #2667 — the value-source picker is outbound-only, so hidden for inbound.
  await expect(page.getByRole('combobox', { name: /^(kanał|channel)$/i })).toHaveCount(0);

  // Switch to bidirectional → the conflict policy card appears.
  await page.getByRole('button', { name: /dwukierunkowy|bidirectional/i }).click();
  await expect(page.getByRole('button', { name: /last-write-wins/i })).toBeVisible();

  // #2667 — pick the value-source channel + language before saving.
  const channelSelect = page.getByRole('combobox', { name: /^(kanał|channel)$/i });
  await expect(channelSelect).toBeVisible();
  await channelSelect.selectOption('baselinker');
  await page.getByRole('combobox', { name: /^(język|language)$/i }).selectOption('en');

  // Save the binding.
  await page.getByRole('button', { name: /zapisz wiązanie|save binding/i }).click();
  await expect.poll(() => patched).toBe(true);
  expect(lastPatch.sourceChannel).toBe('baselinker');
  expect(lastPatch.sourceLocale).toBe('en');

  // Run now.
  await page.getByRole('button', { name: /uruchom teraz|run now/i }).click();
  await expect.poll(() => ran).toBe(true);

  // Long-standing flake: axe used to catch the success toast mid-fade and
  // report a bogus color-contrast on the animating overlay. Wait for the
  // toast to fully dismiss before auditing.
  await expect(page.getByRole('status')).toHaveCount(0, { timeout: 10_000 });

  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);
});

/**
 * Regression: a binding with no schedule has `nextRun` ABSENT from the API
 * response (not `null`), so the FE receives `undefined`. A strict `!== null`
 * guard fell through to `new Date(undefined)` → "Invalid Date" in the next-run
 * box; the loose `!= null` must instead show the manual-only copy.
 */
test('APIC-P3-11 — sync config: no schedule shows "manual only", never "Invalid Date"', async ({
  page,
}) => {
  await loginAsAdmin(page);

  const binding = {
    id: 'bind-1',
    connectionId: 'conn-1',
    objectTypeId: 'ot-1',
    readEndpointId: null,
    writeEndpointId: null,
    direction: 'inbound',
    schedule: null,
    conflictPolicy: 'lww',
    matchKeyMapping: null,
    cursor: null,
    isEnabled: false,
    // nextRun intentionally omitted — mirrors the API omitting it when unscheduled.
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
      body: JSON.stringify({ member: [], totalItems: 0 }),
    }),
  );
  await page.route('**/api/object_types**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [{ id: 'ot-1', code: 'product' }], totalItems: 1 }),
    }),
  );

  await page.goto('/integrations/api-configurator/connections/conn-1/sync');
  await expect(
    page.getByRole('heading', {
      name: /konfiguracja synchronizacji|synchronization configuration/i,
    }),
  ).toBeVisible();

  // The bug: "Invalid Date" must never render.
  await expect(page.getByText(/invalid date/i)).toHaveCount(0);
  // The next-run box shows the manual-only copy instead.
  await expect(page.getByText(/run manually|uruchamiane ręcznie/i)).toBeVisible();
});
