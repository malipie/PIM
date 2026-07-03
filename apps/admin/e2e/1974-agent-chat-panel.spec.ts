import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * AGENT-P6-01 (#1974) — agent chat panel (Sheet): opens from the
 * topbar, is accessible (axe), submits an intent and renders the
 * backend answer honestly. The CI stack has NO BYOK key, so the
 * expected outcome of a submit is the feature guard's 403 problem
 * rendered as an inline error — the full happy path needs a real key
 * (LLM-live smoke pending BYOK).
 */
test('agent chat opens, passes axe and surfaces the no-key refusal', async ({ page }) => {
  await loginAsAdmin(page);

  await page.getByTestId('agent-chat-trigger').click();
  await expect(page.getByTestId('agent-chat-input')).toBeVisible();

  const a11y = await new AxeBuilder({ page })
    .include('[data-testid="agent-chat-messages"]')
    .analyze();
  const serious = a11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(serious).toEqual([]);

  await page.getByTestId('agent-chat-input').fill('ustaw cenę 100 wszystkim produktom bez ceny');
  await page.getByTestId('agent-chat-send').click();

  // No BYOK key on the CI stack -> the guard refuses; the panel must
  // surface the reason instead of crashing or pretending.
  await expect(page.getByTestId('agent-chat-error')).toBeVisible();
  await expect(page.getByTestId('agent-chat-error')).not.toHaveText('');
});
