import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * IMP2-2.3 (#1479) — pause / resume / cancel controls on the import session
 * view. The control bar renders only for a non-terminal session (running /
 * paused / pending). Mirrors the defensive 1430 spec: skips when the
 * environment has no in-flight import (the substantive pause→resume→success
 * behaviour is covered by ImportPauseResumeTest + a live-stack smoke, since a
 * deterministically-timed async import is flaky in E2E).
 */
test('IMP2-2.3 — session view exposes pause/cancel controls for an in-flight import', async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/sessions');

  // Prefer a non-terminal session from the history table.
  // Scope to table rows: the PL sessions page renders static "W toku"
  // (KPI card + live-section heading) that would false-positive a
  // page-wide text match and defeat the skip guard.
  const liveBadge = page
    .getByRole('row')
    .getByText(/w toku|running|wstrzyman|paused|oczekuj|pending/i)
    .first();
  const hasLive = await liveBadge
    .waitFor({ state: 'visible', timeout: 8_000 })
    .then(() => true)
    .catch(() => false);
  test.skip(!hasLive, 'No in-flight import session in this environment');

  const fileCell = page.getByText(/\.csv|\.xlsx/i).first();
  await fileCell.click();
  await expect(page).toHaveURL(/\/integrations\/imports\/[0-9a-f-]{8,}/);

  // The control bar is present for non-terminal sessions; at minimum the
  // Cancel control is offered (pause for running, resume for paused).
  const controls = page.getByTestId('import-controls');
  await expect(controls).toBeVisible({ timeout: 10_000 });
  await expect(controls.getByRole('button', { name: /anuluj|cancel/i })).toBeVisible();
  await expect(controls.getByRole('button', { name: /pauza|pause|wznów|resume/i })).toBeVisible();
});
