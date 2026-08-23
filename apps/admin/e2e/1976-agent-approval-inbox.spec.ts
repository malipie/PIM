import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

const RUN_ID = '0197c3b0-0000-7000-8000-00000000cafe';

const runSummary = (status: string) => ({
  id: RUN_ID,
  status,
  surface: 'chat',
  intent: 'ustaw cenę 100 wszystkim bez ceny',
  model: 'claude-sonnet-4-6',
  pending_change_batch_id: '0197c3b0-0000-7000-8000-00000000beef',
  affected_count: 1800,
  bulk_operation_id: null,
  tokens_input: 1200,
  tokens_output: 400,
  cost_usd: '0.009600',
  approved_by: null,
  approved_at: null,
  started_at: new Date().toISOString(),
  completed_at: null,
});

/**
 * AGENT-P6-03 (#1976) — approval inbox UI contract on intercepted API
 * (the backend accept path is covered by ApiTestCase; a real
 * awaiting_approval run needs an LLM -> LLM-live smoke pending BYOK):
 * the list shows intent + scope + cost + provenance, the diff modal
 * renders before->after rows, approve fires POST and the run leaves
 * the inbox.
 */
test('inbox lists the plan, shows the diff and approves', async ({ page }) => {
  await loginAsAdmin(page);

  let approved = false;
  await page.route(`**/api/agent/inbox?*`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        items: approved ? [] : [runSummary('awaiting_approval')],
        total: approved ? 0 : 1,
        page: 1,
        per_page: 100,
      }),
    }),
  );
  await page.route(`**/api/agent/runs/${RUN_ID}/plan?*`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        items: [
          {
            id: '0197c3b0-0000-7000-8000-000000000001',
            change_type: 'value',
            status: 'pending',
            target_object_id: '0197c3b0-0000-7000-8000-000000000002',
            attribute_code: 'price',
            scope_locale: null,
            scope_channel: null,
            before: null,
            after: { value: 100 },
            provenance: 'agent',
          },
        ],
        total: 1800,
        page: 1,
        per_page: 100,
      }),
    }),
  );
  await page.route(`**/api/agent/runs/${RUN_ID}/approve`, (route) => {
    approved = true;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(runSummary('done')),
    });
  });

  await page.goto('/dashboard');
  await expect(page.getByTestId('nav-agent-inbox')).toBeVisible();
  await expect(page.getByTestId('nav-agent-inbox-count')).toHaveText('1');
  const navA11y = await new AxeBuilder({ page })
    .include('[data-testid="nav-agent-inbox"]')
    .analyze();
  expect(
    navA11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'),
  ).toEqual([]);
  await page.getByTestId('nav-agent-inbox').click();
  await expect(page.getByTestId('agent-inbox-item')).toBeVisible();
  await expect(page.getByTestId('agent-inbox-item')).toContainText('1800');
  await expect(page.getByTestId('agent-inbox-item')).toContainText('0.009600');

  await page.goto(`/agent/inbox?run=${RUN_ID}&batch=0197c3b0-0000-7000-8000-00000000beef`);
  await expect(page.getByTestId('agent-diff-modal')).toBeVisible();
  await expect(page.getByTestId('agent-diff-row')).toContainText('price: ∅ → 100');

  const a11y = await new AxeBuilder({ page }).include('[data-testid="agent-diff-modal"]').analyze();
  expect(a11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );

  await page.getByTestId('agent-diff-approve').click();
  await expect(page.getByTestId('agent-inbox-empty')).toBeVisible();
  await expect(page.getByTestId('nav-agent-inbox-count')).toHaveText('0');
  expect(approved).toBe(true);
});
