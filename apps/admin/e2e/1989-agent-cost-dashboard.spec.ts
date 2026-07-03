import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * AGENT-P9-02 (#1989) — the cost/limits panel in Settings -> AI renders
 * the tenant spend + cap progress from the real /api/agent/cost
 * endpoint (intercepted so the numbers are deterministic; the aggregate
 * SQL is covered by the integration test).
 */
test('cost panel shows spend and cap progress', async ({ page }) => {
  await loginAsAdmin(page);

  await page.route('**/api/agent/cost', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        cost_today_usd: '12.500000',
        cost_month_usd: '210.000000',
        tokens_today: 45000,
        tokens_month: 900000,
        runs_today: 7,
        runs_month: 120,
        day_cap_usd: 20,
        month_cap_usd: 300,
        day_cap_pct: 62.5,
        month_cap_pct: 70,
        per_user_today: [],
      }),
    }),
  );

  await page.goto('/settings/ai');
  await expect(page.getByTestId('agent-cost')).toBeVisible();
  await expect(page.getByTestId('agent-cost-today')).toContainText('12.500000');
  await expect(page.getByTestId('agent-cap-pct').first()).toContainText('62.5%');
});
