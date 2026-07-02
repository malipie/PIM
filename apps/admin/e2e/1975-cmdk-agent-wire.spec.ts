import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * AGENT-P6-02 (#1975) — the Cmd+K agent section is REAL: a free-form
 * command on the products list goes to POST /api/agent/runs carrying
 * the view context (object type, filter DSL slot, selection) and hands
 * off to the chat sheet. The API call is intercepted so the contract
 * is asserted without a BYOK key (LLM-live smoke pending).
 */
test('cmd+k sends a free-form command to the agent with view context', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');
  await expect(page).toHaveURL(/\/products$/);
  // Wait for the list shell (the mod+k handler binds on mount).
  await expect(page.locator('main')).toBeVisible();

  let capturedBody: Record<string, unknown> | null = null;
  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    capturedBody = route.request().postDataJSON() as Record<string, unknown>;
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        id: '0197c3b0-0000-7000-8000-00000000abcd',
        status: 'awaiting_input',
        surface: 'cmdk',
        intent: 'przecen produkty festo o 10%',
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
  await page.route('**/api/agent/runs/0197c3b0-0000-7000-8000-00000000abcd', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: '0197c3b0-0000-7000-8000-00000000abcd',
        status: 'awaiting_input',
        surface: 'cmdk',
        intent: 'przecen produkty festo o 10%',
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

  // The list route owns mod+k (VIEW-19 palette). The handler binds when
  // the lazy list page mounts, which lags `main` becoming visible — so
  // retry the shortcut until the palette answers instead of pressing once.
  const dialog = page.getByRole('dialog');
  await expect(async () => {
    await page.keyboard.press('ControlOrMeta+k');
    await expect(dialog).toBeVisible({ timeout: 1000 });
  }).toPass({ timeout: 15_000 });
  const commandInput = dialog.getByRole('textbox');
  await expect(commandInput).toBeVisible();

  // Free-form command → the local planner falls back → "send to agent".
  await commandInput.fill('przecen produkty festo o 10%');
  await commandInput.press('Enter');
  await page.getByTestId('cmdk-list-ask-agent').click();

  await expect.poll(() => capturedBody).not.toBeNull();
  const body = capturedBody as unknown as {
    intent: string;
    surface: string;
    context: Record<string, unknown>;
  };
  expect(body.intent).toBe('przecen produkty festo o 10%');
  expect(body.surface).toBe('cmdk');
  expect(body.context.object_type_code).toBe('product');
  expect(body.context).toHaveProperty('filter_dsl');
  expect(body.context).toHaveProperty('selected_ids');
  expect(body.context).toHaveProperty('total_matching');

  // Hand-off: the chat sheet adopted the run.
  await expect(page.getByTestId('agent-run-status')).toBeVisible();
});
