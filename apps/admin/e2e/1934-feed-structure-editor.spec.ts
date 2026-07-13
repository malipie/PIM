import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-06 (#1934) — the structure editor step for custom feeds: the step
 * appears only for template_kind=custom, inline validation blocks illegal XML
 * names and duplicate slots, and a valid structure lets the wizard continue
 * into scope. All backend calls mocked.
 */

const TEMPLATES = {
  items: [
    {
      kind: 'google_shopping',
      built_in: true,
      root_element: 'rss',
      namespaces: ['g'],
      descriptor: { root: { element: 'rss' } },
      default_mappings: [{ slot: 'g:id', source: { kind: 'attribute', ref: 'sku' } }],
    },
    {
      kind: 'custom',
      built_in: false,
      root_element: 'products',
      namespaces: [],
      descriptor: {
        root: { element: 'products' },
        item: {
          element: 'product',
          slots: [
            { target: 'sku', node: 'element', element: 'sku', required: true, fmt: 'text' },
            { target: 'name', node: 'element', element: 'name', maxLength: 255, fmt: 'text' },
          ],
        },
      },
      default_mappings: [
        { slot: 'sku', source: { kind: 'attribute', ref: 'sku' } },
        { slot: 'name', source: { kind: 'attribute', ref: 'name' } },
      ],
    },
  ],
};

test('XMLF-P5-06 — structure editor: custom-only step, inline validation, outline', async ({
  page,
}) => {
  await loginAsAdmin(page);

  await page.route('**/api/feeds/**', (route) => route.fulfill({ json: {} }));
  await page.route('**/api/feeds/templates', (route) => route.fulfill({ json: TEMPLATES }));
  await page.route('**/api/object_types*', (route) =>
    route.fulfill({
      json: {
        member: [{ id: '019f0000-0000-7000-8000-000000000001', kind: 'product' }],
        totalItems: 1,
      },
    }),
  );

  await page.goto('/integrations/api-configurator/feeds/new');

  const nextButton = page.getByRole('button', { name: /Dalej|^Next$/ });
  const stepper = page.getByRole('navigation', { name: /kroki|steps/i });

  // A predefined template has no structure step in the stepper.
  await page.getByRole('button', { name: /Google Shopping/ }).click();
  await expect(stepper.getByText(/Struktura|Structure/)).toHaveCount(0);

  // Switching to Custom inserts the structure step between template and scope.
  await page.getByRole('button', { name: /Własny|Custom/ }).click();
  await expect(stepper.getByText(/Struktura|Structure/)).toBeVisible();
  await nextButton.click();
  await expect(page.getByText(/Struktura dokumentu|Document structure/)).toBeVisible();

  // The blank starter renders root/item and the two seeded slots + outline.
  const rootInput = page.getByRole('textbox', { name: /Element główny|Root element/ });
  await expect(rootInput).toHaveValue('products');
  const nameInputs = page.getByRole('textbox', { name: /Nazwa pola|Field name/ });
  await expect(nameInputs).toHaveCount(2);
  await expect(page.getByText('<products>')).toBeVisible();

  // Illegal XML name → inline error + Next gated (backend guard mirrored).
  await nameInputs.nth(1).fill('description.pl');
  await expect(page.getByRole('alert').first()).toContainText(/description\.pl/);
  await expect(nextButton).toBeDisabled();

  // Duplicate slot names are flagged too.
  await nameInputs.nth(1).fill('sku');
  await expect(page.getByRole('alert').first()).toContainText(/sku/);
  await expect(nextButton).toBeDisabled();

  // Fix the name, add a fresh slot — the outline follows live.
  await nameInputs.nth(1).fill('name');
  await page.getByRole('button', { name: /Dodaj pole|Add field/ }).click();
  await nameInputs.nth(2).fill('price');
  await expect(page.getByText('<price>…</price>')).toBeVisible();
  await expect(nextButton).toBeEnabled();

  // An attribute node demands a parent element before the step can be left.
  await page
    .getByRole('combobox', { name: /Rodzaj węzła|Node kind/ })
    .nth(2)
    .selectOption('attribute');
  await expect(nextButton).toBeDisabled();
  await page.getByRole('textbox', { name: /Element nadrzędny|Parent element/ }).fill('o');
  await expect(nextButton).toBeEnabled();

  // a11y on the structure editor. Exclude CTA buttons: the brand orange
  // (#ff4f00) is an accepted sub-AA-contrast exception per the design system.
  const axe = await new AxeBuilder({ page }).exclude('.bg-cta').analyze();
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );

  // A valid structure continues into scope.
  await nextButton.click();
  await expect(page.getByRole('combobox').first()).toBeVisible();
  await expect(page.getByText(/Struktura dokumentu|Document structure/)).toHaveCount(0);
});
