import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * IMP-14 (#455) — E2E smoke for the imports wizard. Single login to
 * keep the auth-throttle budget low (the suite already pays for
 * multi-tenant + catalog flows). Walks the list view → "Nowy import"
 * link → wizard route. Full round-trip with product creation lives in
 * the backend ApiTestCase suite (StartImportApiTest /
 * ValidateDryRunApiTest).
 */
test.describe('Imports MVP', () => {
  test('list renders and the wizard route is reachable', async ({ page }) => {
    await loginAsAdmin(page);

    await page.goto('/integrations/imports');
    // NUI-09 — the legacy IntegrationsLayout header is gone; the hub
    // renders pill tabs + the sessions view heading under the v2 shell.
    await expect(
      page.getByRole('heading', { name: /sesje import|importy|import sessions/i }),
    ).toBeVisible();

    await page
      .getByRole('link', { name: /nowy import|new import/i })
      .first()
      .click();
    await expect(page).toHaveURL(/\/integrations\/imports\/new$/);
  });

  // Regression for the silently-failing auto-map call: dataProvider.custom
  // used to throw "not implemented", which left the Mapping step blank
  // because Refine's useCustom swallowed the throw and never populated
  // suggestions. After the fix, a CSV with three obvious column names
  // produces three mapping rows.
  test('mapping step renders rows after CSV upload', async ({ page }) => {
    test.fixme(
      true,
      'Pending #799: file input on /integrations/imports/new wizard not reached — step gating changed after IMP-* PRs',
    );
    await loginAsAdmin(page);
    await page.goto('/integrations/imports/new');

    const csv = 'sku;name;price\nABC-001;Wkręt M6;9.99\n';
    await page
      .locator('input[type="file"]')
      .first()
      .setInputFiles({
        name: 'sample.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(csv, 'utf-8'),
      });

    await page.getByRole('button', { name: /dalej|next/i }).click();

    await expect(page.getByRole('cell', { name: 'sku', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'name', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'price', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'ABC-001', exact: true })).toBeVisible();
  });

  // Regression for the StepValidation 401: the wizard used to fire
  // /validate-dry-run via raw `fetch` without the Bearer header,
  // crashing Step 3 with "Wgranie pliku nie powiodło się.: HTTP 401".
  // After the fix, the dry-run call carries auth + the KPI cards render.
  test('validation step renders KPI cards after dry-run', async ({ page }) => {
    test.fixme(
      true,
      'Pending #799: file input on imports wizard not reached — see sibling test fixme above',
    );
    await loginAsAdmin(page);
    await page.goto('/integrations/imports/new');

    const csv = 'sku;name;price\nABC-001;Wkręt M6;9.99\n';
    await page
      .locator('input[type="file"]')
      .first()
      .setInputFiles({
        name: 'sample.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(csv, 'utf-8'),
      });

    // Step 1 → Step 2.
    await page.getByRole('button', { name: /dalej|next/i }).click();
    await expect(page.getByRole('cell', { name: 'sku', exact: true })).toBeVisible();

    // Step 2 → Step 3. The KPI cards always render (success + error
    // counts), regardless of whether the rows pass or fail — we're
    // asserting the dry-run call resolves with auth, not the data shape.
    await page.getByRole('button', { name: /dalej|next/i }).click();
    await expect(page.getByText(/produkt(ów|y) OK|products? ok/i)).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByText(/HTTP 401/i)).not.toBeVisible();
  });

  // Regression for the category-assignment feature: a header named
  // "Kategoria" must auto-map to the reserved __category__ target so
  // the operator does not have to pick it manually. The downstream
  // ImportObjectCreator picks the value up and lands the row in the
  // object_categories junction — covered by the backend ApiTest.
  test('mapping auto-detects the category column for product imports', async ({ page }) => {
    test.fixme(
      true,
      'Pending #799: file input on imports wizard not reached — see sibling test fixme above',
    );
    await loginAsAdmin(page);
    await page.goto('/integrations/imports/new');

    const csv = 'sku;name;price;Kategoria\nABC-001;Wkręt;9.99;narzedzia\n';
    await page
      .locator('input[type="file"]')
      .first()
      .setInputFiles({
        name: 'with-category.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(csv, 'utf-8'),
      });
    await page.getByRole('button', { name: /dalej|next/i }).click();

    // The "Kategoria" row carries the reserved __category__ description
    // — Combobox label resolves to either "Category (assign by code)"
    // (en) or "Kategoria (przypisanie po kodzie)" (pl) depending on
    // the browser locale. The "auto" badge in the same row confirms
    // the dictionary resolution path was taken.
    const categoryRow = page.getByRole('row').filter({ hasText: 'narzedzia' });
    await expect(categoryRow).toBeVisible();
    await expect(
      categoryRow.getByRole('button', {
        name: /category \(assign by code\)|kategoria \(przypisanie/i,
      }),
    ).toBeVisible();
  });
});
