import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-02 (#1930) — the 5-step feed wizard shell with template + scope:
 * template cards from GET /api/feeds/templates seed name/code + mappings,
 * step gating (Dalej disabled until kind && name), the scope step reuses the
 * shared AdvancedFilterPanel with a live preflight count, and leaving scope
 * persists the draft (POST /api/feeds). All endpoints are mocked.
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
      descriptor: { root: { element: 'products' } },
      default_mappings: [],
    },
  ],
};

test('XMLF-P5-02 — wizard: template choice, gating, scope + draft save', async ({ page }) => {
  await loginAsAdmin(page);

  // Registered before the more specific routes — Playwright matches LIFO,
  // so `/api/feeds/templates` (below) wins over this id-wildcard.
  await page.route('**/api/feeds/*', (route) => {
    if (route.request().method() === 'PATCH') {
      return route.fulfill({ json: route.request().postDataJSON() as Record<string, unknown> });
    }
    return route.fulfill({ json: {} });
  });
  await page.route('**/api/feeds/templates', (route) => route.fulfill({ json: TEMPLATES }));
  await page.route('**/api/object_types*', (route) =>
    route.fulfill({
      json: {
        member: [{ id: '019f0000-0000-7000-8000-000000000001', kind: 'product' }],
        totalItems: 1,
      },
    }),
  );
  await page.route('**/api/tenant-locales', (route) =>
    route.fulfill({
      json: {
        items: [
          { code: 'pl', label: 'Polski', isActive: true },
          { code: 'en', label: 'English', isActive: true },
        ],
      },
    }),
  );
  await page.route('**/api/channels*', (route) =>
    route.fulfill({ json: { member: [], totalItems: 0 } }),
  );
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      json: { count: 12408, mode: 'async', threshold: 500, soft_cap: 100000, exceeds_cap: false },
    }),
  );
  let createdPayload: Record<string, unknown> | null = null;
  await page.route('**/api/feeds', (route) => {
    if (route.request().method() === 'POST') {
      createdPayload = route.request().postDataJSON() as Record<string, unknown>;
      return route.fulfill({
        status: 201,
        json: { ...createdPayload, id: '019f0000-0000-7000-8000-00000000aaaa' },
      });
    }
    return route.fulfill({ json: { items: [] } });
  });

  await page.goto('/integrations/api-configurator/feeds/new');

  // Step 1: Next is gated until a template is chosen (name auto-fills).
  const nextButton = page.getByRole('button', { name: /Dalej|^Next$/ });
  await expect(nextButton).toBeDisabled();
  await page.getByRole('button', { name: /Google Shopping/ }).click();

  const nameInput = page.getByPlaceholder(/Google Shopping/);
  await expect(nameInput).not.toHaveValue('');
  const codeInput = page.getByPlaceholder('google_pl');
  await expect(codeInput).toHaveValue(/google_shopping/);
  await expect(nextButton).toBeEnabled();
  await nextButton.click();

  // Step 2: scope + shared filter panel + live count from the preflight.
  await expect(page.getByText(/12\s?408/).first()).toBeVisible();
  await expect(page.getByRole('combobox').first()).toBeVisible();

  // Leaving scope persists the draft.
  await nextButton.click();
  await expect(page.getByText(/XMLF-P5-03/)).toBeVisible();
  expect(createdPayload).not.toBeNull();
  expect(createdPayload?.template_kind).toBe('google_shopping');
  expect(createdPayload?.locale).toBe('pl');

  // Stepper: done steps stay clickable, pending ones do not.
  await expect(page.getByRole('button', { name: /Szablon|Template/ })).toBeEnabled();
  await expect(page.getByRole('button', { name: /Podgląd|Preview/ })).toBeDisabled();

  // a11y — no serious/critical violations on the scope/mapping shell.
  const axe = await new AxeBuilder({ page }).analyze();
  const blocking = axe.violations.filter(
    (violation) => violation.impact === 'serious' || violation.impact === 'critical',
  );
  expect(blocking).toEqual([]);
});
