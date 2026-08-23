import { expect, test } from '@playwright/test';

import { apiLogin, loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * EXP-15 (#594) + EXP-21 (#633), rewritten for the EXR wizard (EXR-14).
 *
 * The business scenarios from PRD §15.1 survive the modal → wizard
 * migration:
 *   1. hub smoke — pill tabs + CTA opens the wizard.
 *   2. list selection → wizard entry with target_scope=selected
 *      (replaces the modal-from-bulk-bar flow).
 *   3. async 202 → redirect to sessions (mocked endpoint).
 *   4. #1244/#1278 locale fan-out contract — bare key absent for
 *      localizable attrs, no duplicates (now asserted on the v2 picker).
 *
 * Świadome odejście: round-trip reimport (scenario e) — EXP-22
 * follow-up blocked by IMP-16..IMP-19 (#602–#605).
 */

test('exports hub MVP — tabs + new flow smoke', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/exports');

  await expect(page.getByRole('tab', { name: /sessions|sesje/i }).first()).toBeVisible();
  await expect(page.getByRole('tab', { name: /profiles|profile/i }).first()).toBeVisible();

  await page
    .getByRole('link', { name: /nowy eksport|new export/i })
    .first()
    .click();
  await expect(page).toHaveURL(/\/integrations\/exports\/new$/);
  await expect(page.getByRole('radiogroup')).toBeVisible();
});

test('list selection enters the wizard with target_scope=selected', async ({ page }) => {
  test.fixme(
    true,
    'Pending #799: ACME demo seed is on tenant=acme but loginAsAdmin signs in as admin@demo.localhost — the products list reads demo and finds no ACME masters. Needs `loginAsAcmeAdmin` helper or to switch the seed home tenant.',
  );
  await apiLogin(page);
  await page.goto('/products');

  const firstCheckbox = page.getByRole('checkbox', { name: /zaznacz acme/i }).first();
  await expect(firstCheckbox).toBeVisible({ timeout: 15_000 });
  await firstCheckbox.check();

  // BulkBar Eksport → EXR-14 navigates into the wizard (no modal).
  await page.getByRole('button', { name: /^eksport$|^export$/i }).click();
  await expect(page).toHaveURL(/\/integrations\/exports\/new\?scope=selected/);

  // Step 1 preselected with products; step 2 shows the selected chip.
  await expect(page.getByRole('radio', { name: /Produkty|Products/ })).toHaveAttribute(
    'aria-checked',
    'true',
  );
  await page.getByRole('button', { name: /Dalej|Next/ }).click();
  await expect(page.getByText(/Zaznaczone obiekty: 1|Selected objects: 1/)).toBeVisible();
});

test('selection toolbar, preflight and downloaded file use one tree scope', async ({ page }) => {
  let preflightScope: { selected_object_ids?: string[]; include_variants?: boolean } | null = null;
  let runScope: { selected_object_ids?: string[]; include_variants?: boolean } | null = null;
  const cappedIds = Array.from(
    { length: 10_000 },
    (_, index) => `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`,
  );

  // Simulate the 50,060-result production case. The server returns its capped
  // id set while the hook must still prioritise every currently visible row.
  await page.route('**/api/products/select-all-matching', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ids: cappedIds,
        totalMatched: 50_060,
        capped: true,
        limit: 10_000,
      }),
    }),
  );
  await page.route('**/api/exports/preflight', async (route) => {
    preflightScope = route.request().postDataJSON() as typeof preflightScope;
    const count = preflightScope?.selected_object_ids?.length ?? 0;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        count,
        mode: 'sync',
        threshold: 100,
        soft_cap: 100000,
        exceeds_cap: false,
      }),
    });
  });
  await page.route('**/api/products/export', async (route) => {
    runScope = route.request().postDataJSON() as typeof runScope;
    const count = runScope?.selected_object_ids?.length ?? 0;
    const rows = Array.from({ length: count }, (_, index) => `SKU-${index + 1}`);
    await route.fulfill({
      status: 200,
      contentType: 'text/csv; charset=utf-8',
      headers: { 'content-disposition': 'attachment; filename="scope.csv"' },
      body: ['sku', ...rows].join('\n'),
    });
  });

  // Browser navigation resolves *.localhost without a host-file entry; the
  // Node-side apiLogin helper does not on some local systems.
  await loginAsAdmin(page);
  await page.goto('/products');

  const rows = page.locator('[data-testid^="products-grid-row-"]');
  await expect(rows.nth(1)).toBeVisible({ timeout: 30_000 });
  const visibleCheckboxes = rows.locator('input[type="checkbox"]');
  await visibleCheckboxes.first().check();
  await page.getByRole('button', { name: /zaznacz wszystkie|select all/i }).click();

  await expect(visibleCheckboxes.nth(0)).toBeChecked();
  await expect(visibleCheckboxes.nth(1)).toBeChecked();
  await expect(page.getByTestId('selection-toolbar')).toContainText(/10[\s ]?000/);
  await expect(page.getByTestId('selection-toolbar')).toContainText(/50[\s ]?060/);
  await page.getByRole('button', { name: /^eksport$|^export$/i }).click();

  await page.getByRole('button', { name: /dalej|next/i }).click();
  await page.getByRole('radio', { name: /^CSV/i }).click();
  await expect(page.getByTestId('preflight-badge')).toContainText(/10[\s ]?000/);
  await page.getByRole('button', { name: /dalej|next/i }).click();
  await page.getByRole('button', { name: /dalej|next/i }).click();

  const downloadPromise = page.waitForEvent('download');
  await page.getByTestId('run-export').click();
  const download = await downloadPromise;
  const stream = await download.createReadStream();
  let file = '';
  for await (const chunk of stream) file += chunk.toString();
  const fileRows = file.trim().split('\n').slice(1);

  expect(preflightScope?.include_variants).toBe(false);
  expect(runScope?.include_variants).toBe(false);
  expect(preflightScope?.selected_object_ids).toHaveLength(10_000);
  expect(runScope?.selected_object_ids).toEqual(preflightScope?.selected_object_ids);
  expect(fileRows).toHaveLength(preflightScope?.selected_object_ids?.length ?? 0);
});

test('exports wizard — async 202 redirects to sessions (mocked endpoint)', async ({ page }) => {
  await apiLogin(page);

  await page.route('**/api/products/export', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        id: uniqueSku('SESS').toLowerCase(),
        status: 'pending',
      }),
    });
  });
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        count: 500,
        mode: 'async',
        threshold: 100,
        soft_cap: 100000,
        exceeds_cap: false,
      }),
    }),
  );

  await page.waitForTimeout(1200);
  await page.goto('/integrations/exports/new');
  for (let step = 0; step < 3; step += 1) {
    await page.getByRole('button', { name: /Dalej|Next/ }).click();
    await page.waitForTimeout(400);
  }
  await page.getByTestId('run-export').click();

  await expect(page).toHaveURL(/\/integrations\/exports\/sessions$/, { timeout: 10_000 });
});

test('exports wizard — error body with ok status surfaces a toast, no junk download', async ({
  page,
}) => {
  // Regression: an export that OOMs returned a PHP fatal-error dump as
  // text/html with an ok-ish status. The wizard must NOT save it as a file —
  // it shows an error toast and stays on the wizard.
  await apiLogin(page);

  await page.route('**/api/products/export', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'text/html; charset=UTF-8',
      body: 'Fatal error: Allowed memory size of 268435456 bytes exhausted',
    });
  });
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        count: 500,
        mode: 'async',
        threshold: 100,
        soft_cap: 100000,
        exceeds_cap: false,
      }),
    }),
  );

  await page.waitForTimeout(1200);
  await page.goto('/integrations/exports/new');
  for (let step = 0; step < 3; step += 1) {
    await page.getByRole('button', { name: /Dalej|Next/ }).click();
    await page.waitForTimeout(400);
  }
  await page.getByTestId('run-export').click();

  await expect(page.getByText(/nieprawidłow|invalid response/i)).toBeVisible({ timeout: 10_000 });
  await expect(page).toHaveURL(/\/integrations\/exports\/new$/);
});

test('#1244/#1278 locale fan-out — per-locale rows, no bare duplicate (v2 picker)', async ({
  page,
}) => {
  await page.route('**/api/workspaces/current', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ enabledLocales: ['pl', 'en'], primaryLocale: 'pl' }),
    });
  });
  await page.route('**/api/attributes**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify([
        { id: '1', code: 'description', label: { pl: 'Opis' }, type: 'text', localizable: true },
      ]),
    });
  });
  await page.route('**/api/attribute_groups**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.route('**/api/channels**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        count: 5,
        mode: 'sync',
        threshold: 100,
        soft_cap: 100000,
        exceeds_cap: false,
      }),
    }),
  );

  await apiLogin(page);
  await page.waitForTimeout(1200);
  await page.goto('/integrations/exports/new');
  await page.waitForTimeout(500);
  await page.getByRole('button', { name: /Dalej|Next/ }).click();
  await page.waitForTimeout(300);
  await page.getByRole('button', { name: /Dalej|Next/ }).click();
  await page.waitForTimeout(500);

  const available = page.getByRole('region', {
    name: /dostępne atrybuty|available attributes/i,
  });
  await expect(available).toBeVisible();

  // Localizable attr ships per-locale rows ONLY — the bare code would
  // round-trip into an always-empty column (#1244 contract).
  await expect(available.getByText('description.pl')).toBeVisible();
  await expect(available.getByText('description.en')).toBeVisible();
  await expect(available.getByText(/^description$/)).toHaveCount(0);

  // Selecting PL only lands description.pl (and not .en) in the
  // selected pane.
  await available.getByRole('checkbox', { name: /Opis \[pl\]/i }).check();
  const selected = page.getByRole('region', {
    name: /wybrane atrybuty|selected attributes/i,
  });
  await expect(selected.getByText('description.pl')).toBeVisible();
  await expect(selected.getByText('description.en')).toHaveCount(0);
});
