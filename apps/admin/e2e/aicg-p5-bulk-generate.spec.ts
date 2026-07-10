import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * AICG-P5-03 (#2341) / AICG-P6-03 (#2346) — bulk "Generuj treść AI" from
 * the product list: selection -> modal (count + backend cost preview) ->
 * the dedicated bulk path (POST /api/agent/content/bulk-generate, 202).
 * The agent content API is intercepted (CI has no BYOK key — the 2246/P5a
 * convention).
 */

const RUN_ID = '019f0000-0000-7000-8000-0000000b1b1b';
const BATCH_ID = '019f0000-0000-7000-8000-0000000ba7c1';

function estimateBody(count: number) {
  return {
    product_count: count,
    input_tokens_per_product: 1260,
    output_tokens_per_product: 400,
    est_input_tokens: 1260 * count,
    est_output_tokens: 400 * count,
    est_cost_usd: (count * 0.012).toFixed(6),
    model: 'claude-sonnet-test',
  };
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('bulk generate previews the cost and starts one run via the bulk path', async ({ page }) => {
  let capturedPreview: Record<string, unknown> | null = null;
  let capturedGenerate: Record<string, unknown> | null = null;

  await page.route('**/api/agent/content/cost-preview', async (route) => {
    capturedPreview = route.request().postDataJSON() as Record<string, unknown>;
    const count = Number((capturedPreview as { product_count?: number }).product_count ?? 0);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(estimateBody(count)),
    });
  });
  await page.route('**/api/agent/content/bulk-generate', async (route) => {
    capturedGenerate = route.request().postDataJSON() as Record<string, unknown>;
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        run_id: RUN_ID,
        pending_change_batch_id: BATCH_ID,
        product_count: 2,
        estimate: estimateBody(2),
      }),
    });
  });
  await page.route(`**/api/agent/runs/${RUN_ID}`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: RUN_ID,
        status: 'planning',
        surface: 'cmdk',
        intent: 'bulk e2e',
        model: null,
        pending_change_batch_id: null,
        affected_count: null,
        bulk_operation_id: null,
        tokens_input: 0,
        tokens_output: 0,
        cost_usd: '0.000000',
        approved_by: null,
        approved_at: null,
        started_at: new Date().toISOString(),
        completed_at: null,
        context: {},
        error_message: null,
        messages: [],
        tool_calls: [],
      }),
    }),
  );

  await loginAsAdmin(page);
  await page.goto('/products');

  const rows = page.locator('[data-testid^="products-grid-row-"]');
  await expect(rows.first()).toBeVisible({ timeout: 30_000 });
  await rows.nth(0).getByRole('checkbox').first().check();
  await rows.nth(1).getByRole('checkbox').first().check();

  await page.getByTestId('bulk-generate-content').click();
  await expect(page.getByTestId('bulk-generate-count')).toContainText('2');
  // The backend preview drives the estimate line.
  await expect(page.getByTestId('bulk-generate-estimate')).toContainText('USD');
  await expect.poll(() => capturedPreview !== null).toBeTruthy();
  expect((capturedPreview as { product_count?: number })?.product_count).toBe(2);

  // Switch to SEO mode and start via the bulk-generate endpoint.
  await page.getByRole('button', { name: /meta seo/i }).click();
  await page.getByRole('button', { name: /^generuj$/i }).click();

  await expect.poll(() => capturedGenerate !== null).toBeTruthy();
  const body = capturedGenerate as unknown as {
    mode: string;
    selected_ids: string[];
    object_type_code: string;
  };
  expect(body.mode).toBe('seo');
  expect(body.selected_ids).toHaveLength(2);
  expect(body.object_type_code).toBe('product');

  await expect(page.getByTestId('bulk-generate-count')).toHaveCount(0);
});

test('the day-cap 429 from the backend surfaces as an error and keeps the modal open', async ({
  page,
}) => {
  await page.route('**/api/agent/content/cost-preview', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(estimateBody(2)),
    }),
  );
  await page.route('**/api/agent/content/bulk-generate', (route) =>
    route.fulfill({
      status: 429,
      contentType: 'application/problem+json',
      body: JSON.stringify({
        type: 'https://pim.dev/problems/agent-budget-exceeded',
        title: 'Estimated bulk cost exceeds the remaining daily budget.',
        status: 429,
      }),
    }),
  );

  await loginAsAdmin(page);
  await page.goto('/products');

  const rows = page.locator('[data-testid^="products-grid-row-"]');
  await expect(rows.first()).toBeVisible({ timeout: 30_000 });
  await rows.nth(0).getByRole('checkbox').first().check();
  await rows.nth(1).getByRole('checkbox').first().check();

  await page.getByTestId('bulk-generate-content').click();
  await page.getByRole('button', { name: /^generuj$/i }).click();

  // The modal stays open (the run never started); the count is still shown.
  await expect(page.getByTestId('bulk-generate-count')).toBeVisible();
});
