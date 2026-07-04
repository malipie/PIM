import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * AGENT-P6-06 (#1979) — BYOK settings on the LIVE backend: the page
 * reads the real status endpoint, saving a test key flips the state to
 * enabled with only the prefix visible, disable soft-offs the agent.
 * Runs against the real API (no interception) - the only agent surface
 * fully smokeable without an actual working key.
 */
test('BYOK settings set, rotate-visible-prefix and disable on the live API', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/settings/ai');

  await expect(page.getByTestId('agent-settings')).toBeVisible();
  await expect(page.getByTestId('agent-key-state')).toBeVisible();

  // Right-column shortcuts into the agent inbox / run history
  // (those routes have no sidebar entry, so this is the way in).
  await expect(page.getByTestId('agent-shortcut-inbox')).toHaveAttribute('href', '/agent/inbox');
  await expect(page.getByTestId('agent-shortcut-history')).toHaveAttribute(
    'href',
    '/agent/history',
  );

  const a11y = await new AxeBuilder({ page }).include('[data-testid="agent-settings"]').analyze();
  expect(a11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );

  // Set a syntactically valid test key on the live endpoint.
  await page.getByTestId('agent-key-input').fill('sk-ant-api03-playwright-e2e-test-key');
  await page.getByTestId('agent-key-save').click();
  await expect(page.getByTestId('agent-key-state')).toContainText(/Aktywny|Active/);
  await expect(page.getByTestId('agent-key-prefix')).toBeVisible();
  await expect(page.getByTestId('agent-key-prefix')).not.toContainText('playwright-e2e-test-key');

  // Disable: soft-off for the tenant.
  await page.getByTestId('agent-key-disable').click();
  await expect(page.getByTestId('agent-key-state')).toContainText(/Wyłączony|Disabled/);
});
