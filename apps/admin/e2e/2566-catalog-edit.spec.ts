import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2566 — edit an existing catalog. `/catalogs-pdf/:id/edit` opens the wizard
 * prefilled from GET /api/catalogs/{id}; the final step PATCHes the config
 * (no regeneration) and returns to the hub. All endpoints mocked.
 */

const CATALOG_ID = '019f0000-0000-7000-8000-0000000000ed';

test('#2566 — edit catalog: prefilled wizard + PATCH on save', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });

  await page.route('**/api/object_types', (route) =>
    route.fulfill({
      json: {
        member: [{ id: '11111111-1111-4111-8111-111111111111', code: 'product', kind: 'product' }],
      },
    }),
  );
  await page.route('**/api/attributes**', (route) =>
    route.fulfill({ json: { member: [{ id: 'a1', code: 'name', label: { pl: 'Nazwa' } }] } }),
  );
  await page.route('**/api/search/products**', (route) =>
    route.fulfill({
      json: {
        hits: [],
        totalHits: 5,
        facetDistribution: {},
        processingTimeMs: 1,
        page: 1,
        perPage: 1,
      },
    }),
  );
  await page.route('**/api/catalogs/preview', (route) =>
    route.fulfill({ json: { sample_count: 1, html: '<div>x</div>' } }),
  );

  let patched: { name?: string } | null = null;
  await page.route(`**/api/catalogs/${CATALOG_ID}`, (route) => {
    if (route.request().method() === 'PATCH') {
      patched = route.request().postDataJSON() as { name?: string };
      return route.fulfill({ json: { id: CATALOG_ID, name: patched.name } });
    }
    // GET — the config the edit wizard prefills from.
    return route.fulfill({
      json: {
        id: CATALOG_ID,
        name: 'Katalog wiosna',
        template_kind: 'sheet',
        branding: { color: '#123456', company_name: 'Acme', logo: '' },
        field_mappings: [{ slot: 'title', source: { kind: 'attribute', ref: 'name' } }],
        filter: null,
        locale: null,
      },
    });
  });

  await loginAsAdmin(page);
  await page.goto(`/catalogs-pdf/${CATALOG_ID}/edit`);

  // Edit mode header.
  await expect(
    page.getByRole('heading', { level: 1, name: /edytuj katalog|edit catalog/i }),
  ).toBeVisible();

  const next = page.getByRole('button', { name: /^dalej$|^next$/i });
  await next.click(); // scope → archetype
  await next.click(); // archetype (sheet prefilled) → branding

  // Branding prefilled from the catalog.
  await expect(page.getByLabel(/nazwa firmy|company name/i)).toHaveValue('Acme');
  await next.click(); // → mapping
  await next.click(); // → preview
  await page.getByRole('button', { name: /odśwież podgląd|refresh preview/i }).click();
  await next.click(); // → generate

  // Generate step: name prefilled + edit CTA (no "generate").
  await expect(page.getByLabel(/nazwa katalogu|catalog name/i)).toHaveValue('Katalog wiosna');
  const save = page.getByRole('button', { name: /zapisz zmiany|save changes/i });
  await expect(save).toBeVisible();
  await save.click();

  // PATCH fired and the wizard returned to the hub.
  await expect(page).toHaveURL(/\/catalogs-pdf$/);
  expect(patched).not.toBeNull();
  expect((patched as { name?: string } | null)?.name).toBe('Katalog wiosna');
});
