import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-IMP-04 (#502) — schedule hub smoke. Single login covers the
 * three behaviours (title + new schedule CTA, empty state, dialog
 * open) to stay inside the 5/IP/15min auth rate-limiter.
 */
test('imports schedule hub — title + empty state + add dialog', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/schedule');

  // The in-page "Harmonogram" header was removed in #2671 — the empty state
  // below is the tab's ready signal.

  // Empty state for a fresh tenant.
  await expect(
    page.getByRole('heading', { name: /brak harmonogramów|no schedules yet/i }),
  ).toBeVisible();

  // CTA opens the ScheduleFormDialog.
  await page
    .getByRole('button', { name: /nowe zadanie|new schedule/i })
    .first()
    .click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(
    page.getByRole('heading', { name: /nowe zadanie cron|new cron schedule/i }),
  ).toBeVisible();
});
