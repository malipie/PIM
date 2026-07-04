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

  const a11y = await new AxeBuilder({ page }).include('[data-testid="agent-settings"]').analyze();
  expect(a11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );

  // Right-column shortcuts into inbox / run history are always present
  // (the routes have no sidebar entry — this is the only way in).
  await expect(page.getByTestId('agent-shortcut-inbox')).toHaveAttribute('href', '/agent/inbox');
  await expect(page.getByTestId('agent-shortcut-history')).toHaveAttribute(
    'href',
    '/agent/history',
  );

  // Set a syntactically valid test key on the live endpoint.
  await page.getByTestId('agent-key-input').fill('sk-ant-api03-playwright-e2e-test-key');
  await page.getByTestId('agent-key-save').click();
  await expect(page.getByTestId('agent-key-state')).toContainText(/Aktywny|Active/);
  await expect(page.getByTestId('agent-key-prefix')).toBeVisible();
  await expect(page.getByTestId('agent-key-prefix')).not.toContainText('playwright-e2e-test-key');

  // With a key configured, the model + caching card appears. Pin Haiku
  // (the cheap testing pick) and toggle caching off — both hit the live
  // PATCH endpoint.
  await expect(page.getByTestId('agent-model')).toBeVisible();
  await page.getByTestId('agent-model-select').selectOption('claude-haiku-4-5');
  await expect(page.getByTestId('agent-model-select')).toHaveValue('claude-haiku-4-5');

  // The toggle is a controlled checkbox whose state only flips after the
  // async PATCH + query refetch, so Playwright's check()/uncheck() (which
  // expect the DOM to change on click) fail. Use click() + expect() with
  // auto-retry instead. Idempotent: the E2E hits the live PATCH endpoint
  // and the DB is NOT reset between retries, so force a known state first.
  const caching = page.getByTestId('agent-caching-toggle');
  if (!(await caching.isChecked())) {
    await caching.click();
    await expect(caching).toBeChecked();
  }
  await caching.click();
  await expect(caching).not.toBeChecked();
  await caching.click();
  await expect(caching).toBeChecked();

  // Disable: soft-off for the tenant.
  await page.getByTestId('agent-key-disable').click();
  await expect(page.getByTestId('agent-key-state')).toContainText(/Wyłączony|Disabled/);
});
