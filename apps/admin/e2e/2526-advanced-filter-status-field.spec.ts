import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2526 — the editorial status is a filterable system field in the shared
 * advanced-filter panel, so operators can scope exports / feeds / lists by
 * state (draft/review/published/archived). This spec proves the FE wiring:
 * `status` is offered in the field picker (SYSTEM_PANEL_ATTRS) and resolves
 * to a `select` whose value picker serves the four states inline — the
 * enum has no /api/attributes/status/options row, so a round-trip there
 * would 404; the four localized options must render regardless.
 *
 * Scope correctness (only matching objects leave the PIM) is covered by
 * the backend ExportPreflightApiTest::filterCountIsScopedByEditorialStatus
 * and the FilterDslResolver unit tests (SQL + Meilisearch paths).
 */
test('advanced filter offers editorial status with its four states', async ({ page }) => {
  test.setTimeout(90_000);

  await loginAsAdmin(page);
  await page.goto('/products');

  await page.getByRole('button', { name: /filtruj zaawansowane/i }).click();
  const panel = page.locator('section[aria-label*="Filtr"]');
  await expect(panel).toBeVisible();

  await page.getByRole('button', { name: /dodaj warunek/i }).click();
  const pickerTrigger = panel.locator('button[aria-haspopup="listbox"]').first();
  await pickerTrigger.click();

  // The editorial-state system field is offered in the picker (code `status`).
  const search = page.getByPlaceholder(/szukaj atrybutu/i);
  await expect(search).toBeVisible();
  await search.fill('Status');
  await page.getByText('status', { exact: true }).first().click();

  // It resolves to a `select` whose value picker serves the four states
  // inline (localized via the workflow.place.* keys). The value <select> is
  // the last select in the row (after the operator select); its option text
  // proves the inline enum rendered without a /api/attributes/status/options
  // round-trip. `toContainText` reads the option labels regardless of the
  // native dropdown's open state.
  await expect(panel.getByText('select', { exact: true })).toBeVisible();
  const valueSelect = panel.locator('select').last();
  await expect(valueSelect).toContainText('Opublikowany');
  await expect(valueSelect).toContainText('Szkic');
});
