import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * NUI-11 (#1430) — import session view v2: header (status + duration),
 * stage pipeline, results card with KPIs / ResultBar / report download.
 * Uses the newest session from the history table; skips when the
 * environment has no import sessions yet.
 */
test('NUI-11 — session view renders pipeline, summary and report action', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/sessions');

  // History rows are grid divs with an onClick navigate — click the
  // filename cell and let the event bubble to the row handler.
  const fileCell = page.getByText(/\.csv|\.xlsx/i).first();
  const hasSession = await fileCell
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
  test.skip(!hasSession, 'No import sessions in this environment');

  await fileCell.click();
  await expect(page).toHaveURL(/\/integrations\/imports\/[0-9a-f-]{8,}/);

  // Stage pipeline renders its stages.
  await expect(page.getByText(/parsing/i).first()).toBeVisible();
  await expect(page.getByText(/zapis|writing/i).first()).toBeVisible();

  // Terminal session shows the results card with the report download.
  await expect(page.getByText(/zaimportowano|imported/i).first()).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole('button', { name: /raport|report/i })).toBeVisible();
});

/**
 * IMP2-2.10 (#1486) — the session view surfaces the pre-import backup linked
 * at start. Mocked so the assertion is deterministic regardless of whether the
 * environment has a backup-linked session.
 */
test('IMP2-2.10 — session view shows the linked pre-import backup', async ({ page }) => {
  await loginAsAdmin(page);

  const sessionId = '01900000-0000-7000-8000-0000000000aa';
  await page.route(`**/api/import-sessions/${sessionId}`, (route) =>
    route.fulfill({
      json: {
        id: sessionId,
        status: 'success',
        file_name: 'backup-demo.csv',
        total_rows: 3,
        success_count: 3,
        error_count: 0,
        updated_count: 0,
        skipped_count: 0,
        mode: 'upsert',
        images_downloaded: 0,
        images_failed: 0,
        started_at: '2026-06-15T10:00:00.000+00:00',
        completed_at: '2026-06-15T10:01:00.000+00:00',
        rollback_until: null,
        rolled_back_at: null,
        error_message: null,
        backup: {
          id: '01900000-0000-7000-8000-0000000000b1',
          status: 'completed',
          started_at: '2026-06-15T09:55:00.000+00:00',
        },
      },
    }),
  );

  await page.goto(`/integrations/imports/${sessionId}`);

  await expect(page.getByTestId('import-backup-info')).toContainText(
    /(Backup przed importem|Backup before import).*✅/,
  );
});

/**
 * #1553 follow-up (IMP2-2.6) — the "pominiętych" KPI must read skipped_count,
 * not error_count. Mocked with distinct values (skipped=2, error=1) so a
 * regression that reads error_count would render "1 pominiętych" instead of "2".
 */
test('#1553 — KPI "pominiętych" reads skipped_count, not error_count', async ({ page }) => {
  await loginAsAdmin(page);

  const sessionId = '01900000-0000-7000-8000-0000000000cc';
  await page.route(`**/api/import-sessions/${sessionId}`, (route) =>
    route.fulfill({
      json: {
        id: sessionId,
        status: 'success',
        file_name: 'skipped-demo.csv',
        total_rows: 6,
        success_count: 3,
        error_count: 1,
        updated_count: 0,
        skipped_count: 2,
        mode: 'upsert',
        images_downloaded: 0,
        images_failed: 0,
        started_at: '2026-06-15T10:00:00.000+00:00',
        completed_at: '2026-06-15T10:01:00.000+00:00',
        rollback_until: null,
        rolled_back_at: null,
        error_message: null,
        backup: null,
      },
    }),
  );

  await page.goto(`/integrations/imports/${sessionId}`);

  // The "skipped" KPI reflects skipped_count (2), never error_count (1).
  // Locale-agnostic: the suite runs in EN ("2 skipped") or PL ("2 pominiętych").
  await expect(page.getByText(/2 (skipped|pominiętych)/)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/1 (skipped|pominiętych)/)).toHaveCount(0);
});
