import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * APIC-P4-07 (#1797) — the API-profile builder (new mode). ObjectTypes +
 * attribute pool + profile create are mocked, so the test is deterministic:
 * fill the name (code auto-derives), pick an object type + attribute, create.
 */
test('APIC-P4-07 — profile builder: new profile create', async ({ page }) => {
  await loginAsAdmin(page);

  let posted: Record<string, unknown> | null = null;

  await page.route('**/api/object_types**', (r: Route) =>
    r.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({
        member: [{ id: 'ot-1', code: 'product', label: { en: 'Products' }, kind: 'product' }],
        totalItems: 1,
      }),
    }),
  );
  await page.route('**/api/profiles/builder_options', (r: Route) =>
    r.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        attributes: [
          { code: 'name', label: { en: 'Name' }, type: 'string', groupCode: 'general' },
          { code: 'sku', label: { en: 'SKU' }, type: 'string', groupCode: 'general' },
        ],
      }),
    }),
  );
  await page.route('**/api/api_profiles**', (r: Route) => {
    if (r.request().method() === 'POST') {
      posted = r.request().postDataJSON() as Record<string, unknown>;
      return r.fulfill({
        status: 201,
        contentType: 'application/ld+json',
        body: JSON.stringify({ id: 'prof-new', ...posted }),
      });
    }
    return r.fulfill({
      status: 200,
      contentType: 'application/ld+json',
      body: JSON.stringify({ member: [], totalItems: 0 }),
    });
  });
  // Hub resources (after the post-create redirect).
  for (const res of ['api_keys', 'webhook_deliveries']) {
    await page.route(`**/api/${res}**`, (r: Route) =>
      r.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify({ member: [], totalItems: 0 }),
      }),
    );
  }

  await page.goto('/integrations/api-configurator/profiles/new');

  await expect(
    page.getByRole('heading', { name: /nowy profil api|new api profile/i }),
  ).toBeVisible();

  // Name → code auto-derives.
  await page.getByLabel(/nazwa profilu|profile name/i).fill('Public catalog');
  await expect(page.getByLabel(/^code$/i)).toHaveValue('public-catalog');

  // Pick the object type + an attribute.
  await page.getByRole('button', { name: /products/i }).click();
  await page.getByRole('checkbox').first().check();

  // a11y before the create navigation.
  const a11y = await new AxeBuilder({ page }).analyze();
  expect(a11y.violations).toEqual([]);

  // Create → POST fires with the multiselect payload.
  await page.getByRole('button', { name: /utwórz profil|create profile/i }).click();
  await expect.poll(() => posted).not.toBeNull();
  expect(posted?.code).toBe('public-catalog');
  const objectTypeIds = posted?.objectTypeIds;
  expect(Array.isArray(objectTypeIds) && (objectTypeIds as unknown[]).length).toBe(1);
});
