import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CPDF-P5-02 (#2373) — full-page 6-step catalog generation wizard at
 * /catalogs-pdf/new. Walks the steps (scope → archetype → branding →
 * mapping → preview → generate), mocking the create/generate/preview
 * endpoints for determinism, and asserts the wizard POSTs the right calls
 * and navigates back to /catalogs-pdf on finish. AxeBuilder gate on <main>.
 *
 * The known DNS/lang gotcha: the app renders EN from the user profile unless
 * `i18nextLng=pl` is seeded before the app boots.
 */

const PRODUCT_OT_ID = '11111111-1111-4111-8111-111111111111';

test('CPDF-P5-02 — catalog wizard: steps, generate, navigate back, a11y', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });

  // Resolve the built-in product ObjectType id the wizard auto-picks.
  await page.route('**/api/object_types', (route) =>
    route.fulfill({
      json: { member: [{ id: PRODUCT_OT_ID, code: 'product', kind: 'product', builtIn: true }] },
    }),
  );
  // Attribute catalog for the filter panel + the field-mapping pickers (#2570).
  // Includes the default mapping targets so the prefilled slots resolve.
  await page.route('**/api/attributes**', (route) =>
    route.fulfill({
      json: {
        member: [
          { id: 'a-name', code: 'name', label: { pl: 'Nazwa', en: 'Name' } },
          { id: 'a-sku', code: 'sku', label: { pl: 'SKU', en: 'SKU' } },
          { id: 'a-image', code: 'main_image', label: { pl: 'Zdjęcie', en: 'Image' } },
          { id: 'a-desc', code: 'description', label: { pl: 'Opis', en: 'Description' } },
          { id: 'a-price', code: 'price', label: { pl: 'Cena', en: 'Price' } },
        ],
      },
    }),
  );

  const previewCalls: unknown[] = [];
  await page.route('**/api/catalogs/preview', async (route) => {
    previewCalls.push(route.request().postDataJSON());
    await route.fulfill({
      json: { sample_count: 1, html: '<div style="padding:16px">Podgląd</div>' },
    });
  });

  const createCalls: unknown[] = [];
  await page.route('**/api/catalogs', async (route) => {
    if (route.request().method() === 'POST') {
      createCalls.push(route.request().postDataJSON());
      await route.fulfill({ status: 201, json: { id: 'cat-123' } });
      return;
    }
    await route.fulfill({ json: { items: [] } });
  });

  let generateCalled = false;
  await page.route('**/api/catalogs/cat-123/generate', async (route) => {
    generateCalled = true;
    await route.fulfill({
      status: 202,
      json: { run: { id: 'run-1' }, mercure_topic: 'catalog/cat-123' },
    });
  });

  await loginAsAdmin(page);
  await page.goto('/catalogs-pdf/new');

  // Stepper visible with all six steps.
  await expect(
    page.getByRole('heading', { name: /nowy katalog pdf|new pdf catalog/i }),
  ).toBeVisible();
  await expect(page.getByRole('button', { name: /^zakres$|^scope$/i })).toBeVisible();

  const next = page.getByRole('button', { name: /^dalej$|^next$/i });

  // Step 1 — scope. Continue with an empty (all products) filter.
  await next.click();

  // Step 2 — archetype: pick the sheet card, then continue.
  await page.getByRole('radio', { name: /karta produktu|product sheet/i }).click();
  await next.click();

  // Step 3 — branding: set a brand colour + company name. The logo can be
  // picked from Multimedia (#2569) — assert the picker entry point renders.
  await page.getByLabel(/^kolor marki$|^brand color$/i).fill('#0055aa');
  await page.getByLabel(/nazwa firmy|company name/i).fill('Acme');
  await expect(
    page.getByRole('button', { name: /wybierz z multimediów|choose from multimedia/i }),
  ).toBeVisible();
  await next.click();

  // Step 4 — mapping: the attribute picker (#2570) is prefilled with the
  // template default; the "title" slot resolves to the `name` attribute.
  await expect(page.getByRole('button', { name: /nazwa produktu|product name/i })).toContainText(
    'name',
  );
  await next.click();

  // Step 5 — preview: refresh renders the mocked HTML sample.
  await page.getByRole('button', { name: /odśwież podgląd|refresh preview/i }).click();
  await expect(page.getByText(/produktów w podglądzie|products in preview/i)).toBeVisible();
  expect(previewCalls.length).toBeGreaterThan(0);
  await next.click();

  // Step 6 — generate: name required, then create + generate + navigate back.
  await page.getByLabel(/nazwa katalogu|catalog name/i).fill('Katalog testowy');

  // a11y — scan the last step's main content before submitting. Kill CSS
  // transitions/animations first: axe otherwise samples mid-transition blend
  // colours (the CTA/stepper animate on step navigation) and reports false
  // contrast failures against the settled theme values.
  await page.addStyleTag({
    content: '*,*::before,*::after{transition:none!important;animation:none!important}',
  });
  await page.waitForTimeout(150);
  // Exclude CTA buttons: the brand orange (#ff4f00) is an accepted
  // sub-AA-contrast exception per the operator's design system.
  const axe = await new AxeBuilder({ page }).include('main').exclude('.bg-cta').analyze();
  const blocking = axe.violations.filter(
    (violation) => violation.impact === 'serious' || violation.impact === 'critical',
  );
  expect(blocking).toEqual([]);

  await page.getByRole('button', { name: /utwórz i generuj|create and generate/i }).click();

  await expect(page).toHaveURL(/\/catalogs-pdf$/);
  expect(createCalls.length).toBe(1);
  expect(generateCalled).toBe(true);

  // The create payload carries the BE-contract shapes.
  const body = createCalls[0] as {
    template_kind: string;
    object_type_id: string;
    field_mappings: Array<{ slot: string; source: { kind: string; ref: string } }>;
    branding: Record<string, string>;
    name: string;
    code: string;
  };
  expect(body.template_kind).toBe('sheet');
  expect(body.object_type_id).toBe(PRODUCT_OT_ID);
  expect(body.name).toBe('Katalog testowy');
  expect(body.code).toBeTruthy();
  expect(body.branding.color).toBe('#0055aa');
  expect(body.branding.company_name).toBe('Acme');
  expect(body.field_mappings).toContainEqual({
    slot: 'title',
    source: { kind: 'attribute', ref: 'name' },
  });
});
