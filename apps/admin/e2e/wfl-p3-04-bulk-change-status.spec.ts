import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * WFL-P3-04 (#2426) / wiring #2493 — the bulk "Zmień status" action lives
 * in the UniversalListPage bulk bar (the WFL-P3-04 button originally
 * shipped in the never-mounted BulkActionsToolbar). Selecting product
 * rows and applying a transition posts to
 * `POST /api/products/bulk-actions/change_status` (per-row can()).
 *
 * The endpoint is intercepted (2246/AICG-P5 convention): CI's Meili
 * index doesn't carry freshly created products, so the test selects
 * already-indexed seed rows and asserts the FE wiring — the request
 * payload (target ids + chosen transition) and the success flow — rather
 * than downstream product state.
 */
test('bulk change status posts the selected ids and transition', async ({ page }) => {
  let captured: { target_ids?: string[]; payload?: { transition?: string } } | null = null;

  await page.route('**/api/products/bulk-actions/change_status', async (route) => {
    captured = route.request().postDataJSON() as typeof captured;
    const count = captured?.target_ids?.length ?? 0;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        session_id: '019f0000-0000-7000-8000-0000000c1a11',
        action: 'change_status',
        target_count: count,
        success_count: count,
        skipped_count: 0,
        error_count: 0,
        locked_count: 0,
        locked_ids: [],
        rollback_available_until: null,
        completed_at: new Date(0).toISOString(),
      }),
    });
  });

  await loginAsAdmin(page);
  await page.goto('/products');

  const rows = page.locator('[data-testid^="products-grid-row-"]');
  await expect(rows.first()).toBeVisible({ timeout: 30_000 });
  await rows.nth(0).getByRole('checkbox').first().check();
  await rows.nth(1).getByRole('checkbox').first().check();

  // The action only exists because BulkBar now renders it (the fix).
  const changeStatus = page.getByTestId('bulk-change-status');
  await expect(changeStatus).toBeVisible();
  await changeStatus.click();

  await expect(page.getByTestId('bulk-status-transition')).toBeVisible();
  await page.getByTestId('bulk-status-transition').selectOption('submit_for_review');
  await page.getByTestId('bulk-status-confirm').click();

  // The dialog closes on success — proves the request resolved and the
  // shared onApplied/refetch flow ran.
  await expect(page.getByTestId('bulk-status-transition')).toBeHidden();

  await expect.poll(() => captured !== null).toBeTruthy();
  expect(captured?.target_ids).toHaveLength(2);
  expect(captured?.payload?.transition).toBe('submit_for_review');
});
