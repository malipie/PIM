import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-03 (#1931) — the mapper step over the P3-01 view model: slot table
 * with required/node/fmt badges, unmapped-required error state, the source
 * picker updating the draft, the live sample column resolved from the draft
 * preview XML, and the PUT persisting only mapped slots when leaving the
 * step. All endpoints mocked.
 */

const MAPPING_VIEW = {
  feed_id: '019f0000-0000-7000-8000-00000000aaaa',
  object_type_id: '019f0000-0000-7000-8000-000000000001',
  slots: [
    {
      target: 'g:id',
      element: 'g:id',
      node: 'element',
      required: true,
      required_one_of: [],
      format: 'text',
      max_length: 50,
      enums: [],
      mapping: { slot: 'g:id', source: { kind: 'attribute', ref: 'sku' } },
      mapped: true,
      type_warning: null,
    },
    {
      target: 'g:title',
      element: 'g:title',
      node: 'element',
      required: true,
      required_one_of: [],
      format: 'text',
      max_length: 150,
      enums: [],
      mapping: null,
      mapped: false,
      type_warning: null,
    },
  ],
  attributes: [
    { code: 'sku', label: { pl: 'SKU', en: 'SKU' }, type: 'text' },
    { code: 'name', label: { pl: 'Nazwa', en: 'Name' }, type: 'text' },
  ],
  coverage: {
    slots_total: 2,
    slots_mapped: 1,
    required_total: 2,
    required_mapped: 1,
    missing_required: ['g:title'],
    one_of_groups: [],
  },
  transforms: [
    'none',
    'default',
    'price',
    'number',
    'date',
    'enum_map',
    'template',
    'strip_html',
    'truncate',
  ],
};

const PREVIEW = {
  sample_count: 1,
  xml: '<?xml version="1.0"?><rss xmlns:g="http://base.google.com/ns/1.0"><channel><item><g:id>KL-1</g:id></item></channel></rss>',
  health: [],
};

test('XMLF-P5-03 — mapper: table, badges, source pick, sample, PUT on leave', async ({ page }) => {
  await loginAsAdmin(page);

  let putBody: { mappings?: Array<{ slot: string; source: unknown }> } | null = null;
  await page.route('**/api/feeds/**', (route) => {
    const url = route.request().url();
    if (url.endsWith('/mapping')) {
      if (route.request().method() === 'PUT') {
        putBody = route.request().postDataJSON() as typeof putBody;
        return route.fulfill({ json: MAPPING_VIEW });
      }
      return route.fulfill({ json: MAPPING_VIEW });
    }
    if (route.request().method() === 'PATCH') {
      return route.fulfill({ json: route.request().postDataJSON() as Record<string, unknown> });
    }
    return route.fulfill({ json: {} });
  });
  await page.route('**/api/feeds/preview', (route) => route.fulfill({ json: PREVIEW }));
  await page.route('**/api/feeds/templates', (route) =>
    route.fulfill({
      json: {
        items: [
          {
            kind: 'google_shopping',
            built_in: true,
            root_element: 'rss',
            namespaces: ['g'],
            descriptor: { root: { element: 'rss' } },
            default_mappings: [{ slot: 'g:id', source: { kind: 'attribute', ref: 'sku' } }],
          },
        ],
      },
    }),
  );
  await page.route('**/api/object_types*', (route) =>
    route.fulfill({
      json: {
        member: [{ id: '019f0000-0000-7000-8000-000000000001', kind: 'product' }],
        totalItems: 1,
      },
    }),
  );
  await page.route('**/api/tenant-locales', (route) =>
    route.fulfill({ json: { items: [{ code: 'pl', label: 'Polski', isActive: true }] } }),
  );
  await page.route('**/api/channels*', (route) =>
    route.fulfill({ json: { member: [], totalItems: 0 } }),
  );
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      json: { count: 10, mode: 'sync', threshold: 500, soft_cap: 100000, exceeds_cap: false },
    }),
  );
  await page.route('**/api/feeds', (route) => {
    if (route.request().method() === 'POST') {
      return route.fulfill({
        status: 201,
        json: {
          ...(route.request().postDataJSON() as Record<string, unknown>),
          id: '019f0000-0000-7000-8000-00000000aaaa',
        },
      });
    }
    return route.fulfill({ json: { items: [] } });
  });

  await page.goto('/integrations/api-configurator/feeds/new');
  await page.getByRole('button', { name: /Google Shopping/ }).click();
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();

  // Slot table renders with badges + the unmapped-required error state.
  await expect(page.getByText('g:id', { exact: true })).toBeVisible();
  await expect(page.getByText(/wym\.|req\./).first()).toBeVisible();
  await expect(page.getByText(/brak wartości|no value/)).toBeVisible();

  // The live sample column shows the writer's output for the mapped slot.
  await expect(page.getByText('KL-1')).toBeVisible();

  // Coverage header reflects the backend counters.
  await expect(page.getByText('1/2')).toBeVisible();

  // Pick a source for the unmapped slot, then leave the step → PUT with
  // ONLY mapped slots.
  await page
    .getByRole('combobox', { name: /g:title/ })
    .first()
    .selectOption('name');
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();
  // Step 4 is the live delivery step since P5-04 — its schedule card proves
  // the transition.
  await expect(page.getByText(/Harmonogram regeneracji|Regeneration schedule/)).toBeVisible();
  expect(putBody?.mappings?.every((m) => m.source !== null)).toBe(true);
  expect(putBody?.mappings?.some((m) => m.slot === 'g:title')).toBe(true);

  const axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );
});
