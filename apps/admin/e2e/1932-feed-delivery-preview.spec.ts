import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-04 (#1932) — delivery + preview wizard steps: cron presets bind the
 * draft, the gzip switch and auth segmented work (basic reveals write-only
 * credentials), mint shows the token URL exactly once, leaving delivery
 * PATCHes schedule+delivery, and the preview step renders the sample XML with
 * the well-formed badge, the health report and the save-and-generate exit
 * (regenerate + redirect to the hub). All endpoints mocked.
 */

const PREVIEW = {
  sample_count: 2,
  xml: '<?xml version="1.0"?><products><product><sku>KL-1</sku></product><product><sku>KL-2</sku></product></products>',
  health: [{ sku: 'KL-9', slot: 'cat', level: 'warning', message: 'missing category' }],
};

test('XMLF-P5-04 — delivery + preview: schedule, auth, mint-once, preview, save', async ({
  page,
}) => {
  await loginAsAdmin(page);

  let patchedDelivery: unknown = null;
  let regenerated = false;
  await page.route('**/api/feeds/**', (route) => {
    const url = route.request().url();
    const method = route.request().method();
    if (url.endsWith('/mapping')) {
      return route.fulfill({
        json: {
          feed_id: 'f-1',
          object_type_id: 'ot-1',
          slots: [],
          attributes: [],
          coverage: {
            slots_total: 0,
            slots_mapped: 0,
            required_total: 0,
            required_mapped: 0,
            missing_required: [],
            one_of_groups: [],
          },
          transforms: ['none'],
        },
      });
    }
    if (url.endsWith('/token') && method === 'POST') {
      return route.fulfill({
        status: 201,
        json: {
          token: 'feedtokenfeedtokenfeedtoken12345',
          url: '/api/feeds/pull/t-1/feedtokenfeedtokenfeedtoken12345.xml',
        },
      });
    }
    if (url.includes('/preview')) {
      return route.fulfill({ json: PREVIEW });
    }
    if (url.endsWith('/health')) {
      return route.fulfill({
        json: {
          items_syndicated: 95,
          last_run: { item_count: 95, skipped_count: 3, warning_count: 4, health: 'warning' },
        },
      });
    }
    if (url.endsWith('/regenerate')) {
      regenerated = true;
      return route.fulfill({ status: 202, json: { run: { id: 'r-1', status: 'pending' } } });
    }
    if (method === 'PATCH') {
      const body = route.request().postDataJSON() as { delivery?: unknown };
      if (body.delivery !== undefined) {
        patchedDelivery = body.delivery;
      }
      return route.fulfill({ json: body as Record<string, unknown> });
    }
    return route.fulfill({ json: {} });
  });
  await page.route('**/api/feeds/templates', (route) =>
    route.fulfill({
      json: {
        items: [
          {
            kind: 'custom',
            built_in: false,
            root_element: 'products',
            namespaces: [],
            descriptor: { root: { element: 'products' }, item: { element: 'product', slots: [] } },
            default_mappings: [],
          },
        ],
      },
    }),
  );
  await page.route('**/api/object_types*', (route) =>
    route.fulfill({ json: { member: [{ id: 'ot-1', kind: 'product' }], totalItems: 1 } }),
  );
  await page.route('**/api/tenant-locales', (route) =>
    route.fulfill({ json: { items: [{ code: 'pl', label: 'Polski', isActive: true }] } }),
  );
  await page.route('**/api/channels*', (route) =>
    route.fulfill({ json: { member: [], totalItems: 0 } }),
  );
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      json: { count: 95, mode: 'sync', threshold: 500, soft_cap: 100000, exceeds_cap: false },
    }),
  );
  await page.route('**/api/feeds', (route) => {
    if (route.request().method() === 'POST') {
      return route.fulfill({
        status: 201,
        json: { ...(route.request().postDataJSON() as Record<string, unknown>), id: 'f-1' },
      });
    }
    return route.fulfill({ json: { items: [] } });
  });

  await page.goto('/integrations/api-configurator/feeds/new');
  await page.getByRole('button', { name: /Custom|Własny/ }).click();
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();

  // Step 4 — schedule preset binds the cron input.
  await page.getByRole('button', { name: /codziennie|daily/ }).click();
  await expect(page.getByPlaceholder(/tylko ręcznie|manual only/)).toHaveValue('0 3 * * *');

  // Auth basic reveals write-only credentials.
  await page.getByRole('button', { name: /HTTP Basic/ }).click();
  await expect(page.getByLabel(/Hasło|Password/)).toBeVisible();
  await page.getByRole('button', { name: /Token w URL|Token in URL/ }).click();

  // Mint shows the URL exactly once.
  await page.getByRole('button', { name: /Wygeneruj token|Mint token/ }).click();
  await expect(page.getByText(/widoczny RAZ|shown ONCE/)).toBeVisible();
  await expect(page.getByText(/feedtokenfeedtokenfeedtoken12345\.xml/)).toBeVisible();

  // Leaving delivery persists schedule + delivery.
  await page.getByRole('button', { name: /Dalej|^Next$/ }).click();
  await expect(page.getByText(/well-formed/).first()).toBeVisible();
  expect(patchedDelivery).toMatchObject({ gzip: true, auth: { type: 'none' } });

  // Preview: sample XML + health line + projection from the last run.
  await expect(page.getByText(/KL-1/).first()).toBeVisible();
  await expect(page.getByText(/missing category/)).toBeVisible();
  await expect(page.getByText('95', { exact: true }).first()).toBeVisible();

  // Save & generate → regenerate + redirect to the hub.
  await page.getByRole('button', { name: /Zapisz i wygeneruj|Save and generate/ }).click();
  await expect(page).toHaveURL(/\/integrations\/api-configurator\/feeds$/);
  expect(regenerated).toBe(true);

  const axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );
});
