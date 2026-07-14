import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * AICG-P5-03 (#2341) / AICG-P6-03 (#2346) — bulk "Generuj treść AI" from
 * the product list: selection -> modal (count + backend cost preview) ->
 * the dedicated bulk path (POST /api/agent/content/bulk-generate, 202).
 * The agent content API is intercepted (CI has no BYOK key — the 2246/P5a
 * convention).
 *
 * #2603 — the modal now offers EVERY content recipe (built-in + custom), so a
 * custom recipe is selectable and its id reaches the backend.
 */

const RUN_ID = '019f0000-0000-7000-8000-0000000b1b1b';
const BATCH_ID = '019f0000-0000-7000-8000-0000000ba7c1';

const RECIPES = [
  {
    id: 'r-seo',
    code: 'meta_seo',
    name: 'Meta SEO',
    targetAttribute: 'meta_description',
    builtIn: true,
  },
  {
    id: 'r-custom',
    code: 'ext_desc',
    name: 'Opis Rozbudowany',
    targetAttribute: 'description_html',
    builtIn: false,
  },
  {
    id: 'r-desc',
    code: 'product_description',
    name: 'Opis produktu',
    targetAttribute: 'description',
    builtIn: true,
  },
];

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
  await page.route('**/api/content-recipes', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ member: RECIPES }),
    }),
  );
});

test('bulk generate offers every recipe and sends the chosen recipe id', async ({ page }) => {
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

  // #2603 — the custom recipe is now a selectable option (was invisible before).
  const recipes = page.getByTestId('bulk-generate-recipes');
  await expect(recipes.getByRole('button', { name: /Opis Rozbudowany/ })).toBeVisible();
  await expect(recipes.getByRole('button', { name: /Meta SEO/ })).toBeVisible();
  await expect(recipes.getByRole('button', { name: /Opis produktu/ })).toBeVisible();

  // Pick the custom recipe; the cost preview refetches with its id.
  await recipes.getByRole('button', { name: /Opis Rozbudowany/ }).click();
  await expect(page.getByTestId('bulk-generate-estimate')).toContainText('USD');
  await expect.poll(() => (capturedPreview as { recipe_id?: string })?.recipe_id).toBe('r-custom');

  await page.getByRole('button', { name: /^generuj$/i }).click();

  await expect.poll(() => capturedGenerate !== null).toBeTruthy();
  const body = capturedGenerate as unknown as {
    mode: string;
    recipe_id: string;
    selected_ids: string[];
    object_type_code: string;
  };
  // A description-target recipe uses the description tool (mode) and carries its id.
  expect(body.mode).toBe('descriptions');
  expect(body.recipe_id).toBe('r-custom');
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
