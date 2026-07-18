import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2624 — the global footer no longer carries the mock workspace / ADR
 * registry segment ("Pim · workspace „…" · ADR-009 · proponowany ADR-012");
 * only the version + schema rev remain, right-aligned.
 */
test('app footer shows version only, without the mock workspace/ADR text', async ({ page }) => {
  await loginAsAdmin(page);

  const footer = page.locator('footer').last();
  await expect(footer).toBeVisible();
  await expect(footer).toContainText(/v\d+\.\d+\.\d+/);
  await expect(footer).not.toContainText('ADR-009');
  await expect(footer).not.toContainText(/workspace\s*„/);
});
