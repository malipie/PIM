import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * AICG-P5-03 (#2341) — bulk "Generuj treść AI" from the product list:
 * selection -> modal (count + cost estimate) -> one agent run whose
 * context carries the selection scope. The agent API is intercepted
 * (CI has no BYOK key — the 2246/P5a convention).
 */

const RUN_ID = '019f0000-0000-7000-8000-0000000b1b1b';

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('bulk generate starts one run with the selection scope in its context', async ({ page }) => {
  let capturedStart: Record<string, unknown> | null = null;
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
  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    capturedStart = route.request().postDataJSON() as Record<string, unknown>;
    await route.fulfill({
      status: 201,
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
      }),
    });
  });

  await loginAsAdmin(page);
  await page.goto('/products');

  // Select two rows through their checkboxes.
  const rows = page.locator('[data-testid^="products-grid-row-"]');
  await expect(rows.first()).toBeVisible({ timeout: 30_000 });
  await rows.nth(0).getByRole('checkbox').first().check();
  await rows.nth(1).getByRole('checkbox').first().check();

  // The bulk bar exposes the new action; the modal shows count + estimate.
  await page.getByTestId('bulk-generate-content').click();
  await expect(page.getByTestId('bulk-generate-count')).toContainText('2');
  await expect(page.getByTestId('bulk-generate-estimate')).toContainText('USD');

  // Switch to SEO mode and start.
  await page.getByRole('button', { name: /meta seo/i }).click();
  await page.getByRole('button', { name: /^generuj$/i }).click();

  await expect.poll(() => capturedStart !== null).toBeTruthy();
  const body = capturedStart as unknown as {
    intent: string;
    context: { selected_ids: string[]; total_matching: number; object_type_code: string };
  };
  expect(body.intent).toContain('generate_seo_text');
  expect(body.context.selected_ids).toHaveLength(2);
  expect(body.context.total_matching).toBe(2);
  expect(body.context.object_type_code).toBe('product');

  // The modal closed and the selection cleared.
  await expect(page.getByTestId('bulk-generate-count')).toHaveCount(0);
});

test('over-cap selection disables the start button with an explanation', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');

  const rows = page.locator('[data-testid^="products-grid-row-"]');
  await expect(rows.first()).toBeVisible({ timeout: 30_000 });
  const rowCount = Math.min(await rows.count(), 9);
  test.skip(rowCount < 9, 'demo page holds fewer than 9 rows');
  for (let i = 0; i < rowCount; i += 1) {
    await rows.nth(i).getByRole('checkbox').first().check();
  }

  await page.getByTestId('bulk-generate-content').click();
  await expect(page.getByTestId('bulk-generate-cap')).toBeVisible();
  await expect(page.getByRole('button', { name: /^generuj$/i })).toBeDisabled();
});
