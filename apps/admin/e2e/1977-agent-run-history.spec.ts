import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

const RUN_ID = '0197c3b0-0000-7000-8000-00000000d0d0';

const runSummary = (status: string) => ({
  id: RUN_ID,
  status,
  surface: 'chat',
  intent: 'ustaw cenę 100 wszystkim bez ceny',
  model: 'claude-sonnet-4-6',
  pending_change_batch_id: '0197c3b0-0000-7000-8000-00000000beef',
  affected_count: 1800,
  bulk_operation_id: '0197c3b0-0000-7000-8000-00000000feed',
  tokens_input: 1200,
  tokens_output: 400,
  cost_usd: '0.009600',
  approved_by: null,
  approved_at: null,
  started_at: new Date().toISOString(),
  completed_at: null,
});

/**
 * AGENT-P6-04 (#1977) — run history UI contract on intercepted API
 * (the rollback backend is covered by ApiTestCase + integration; a
 * real done run needs an LLM -> LLM-live smoke pending BYOK): list
 * with status/scope/cost, expandable tool calls, rollback flips the
 * status, and a blocked schema rollback surfaces the 409 reason.
 */
test('history lists runs, rolls back and surfaces the schema boundary', async ({ page }) => {
  await loginAsAdmin(page);

  let rolledBack = false;
  let rollbackCalls = 0;
  await page.route('**/api/agent/runs?*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        items: [runSummary(rolledBack ? 'rolled_back' : 'done')],
        page: 1,
        per_page: 50,
      }),
    }),
  );
  await page.route(`**/api/agent/runs/${RUN_ID}`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ...runSummary(rolledBack ? 'rolled_back' : 'done'),
        context: {},
        error_message: null,
        messages: [],
        tool_calls: [
          {
            id: '0197c3b0-0000-7000-8000-00000000aaaa',
            tool: 'bulk_edit_values',
            kind: 'write',
            arguments: {},
            result_summary: null,
            duration_ms: 412,
            created_at: new Date().toISOString(),
          },
        ],
      }),
    }),
  );
  await page.route(`**/api/agent/runs/${RUN_ID}/rollback`, (route) => {
    rollbackCalls += 1;
    if (rollbackCalls === 1) {
      // P5-04 boundary: first attempt refuses (schema-op with data).
      return route.fulfill({
        status: 409,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Conflict',
          status: 409,
          detail:
            'schema: Attribute "weight" already carries 3 value(s) - clear the data first or keep the attribute.',
        }),
      });
    }
    rolledBack = true;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(runSummary('rolled_back')),
    });
  });

  await page.goto('/agent/history');
  await expect(page.getByTestId('agent-history-item')).toBeVisible();
  await expect(page.getByTestId('agent-history-item')).toContainText('0.009600');

  // Expand: tool calls render.
  await page.getByLabel(/Szczegóły runu|Run details/).click();
  await expect(page.getByTestId('agent-history-detail')).toContainText('bulk_edit_values');

  const a11y = await new AxeBuilder({ page }).include('[data-testid="agent-history"]').analyze();
  expect(a11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );

  // First rollback: the P5-04 boundary message surfaces, run stays done.
  await page.getByTestId('agent-history-rollback').click();
  await expect(page.getByTestId('agent-history-error')).toContainText('weight');
  await expect(page.getByTestId('agent-history-status')).toContainText(/Zakończony|Done/);

  // Second rollback succeeds and the status flips.
  await page.getByTestId('agent-history-rollback').click();
  await expect(page.getByTestId('agent-history-status')).toContainText(/Cofnięty|Rolled back/);
});
