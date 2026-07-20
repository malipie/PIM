import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-IMP-01 (#496) — sessions hub smoke. Single login covers the
 * three behaviours (renders KPI + empty live + history, filter pill
 * toggles, "Nowy import" CTA) to stay inside the 5/IP/15min auth
 * rate-limiter (see UI-03 marathon lesson in lessons.md).
 */
test('imports sessions hub — KPI + filter + new-import CTA', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/integrations/imports/sessions');

  // KPI strip labels (the in-page section header was removed in #2671 —
  // the topbar breadcrumb names the area).
  await expect(page.getByText(/^w toku$|^in progress$/i).first()).toBeVisible();
  await expect(page.getByText(/^dziś|^today/i).first()).toBeVisible();
  await expect(page.getByText(/sukces · 30 dni|success · 30 days/i)).toBeVisible();
  await expect(page.getByText(/top błędy · 30 dni|top errors · 30 days/i)).toBeVisible();

  // Empty live area + history heading.
  await expect(page.getByText(/brak aktywnych importów|no active imports/i)).toBeVisible();
  await expect(page.getByRole('heading', { name: /^historia$|^history$/i })).toBeVisible();

  // Filter pill round-trip.
  const successPill = page.getByRole('button', { name: /^sukces$|^success$/i });
  await successPill.click();
  await expect(successPill).toHaveAttribute('aria-pressed', 'true');
  const allPill = page.getByRole('button', { name: /^wszystkie$|^all$/i });
  await allPill.click();
  await expect(allPill).toHaveAttribute('aria-pressed', 'true');

  // "Nowy import" CTA navigates to the wizard. Two exist now (header + the
  // empty active-sessions state), both pointing at the wizard — click the first.
  await page
    .getByRole('link', { name: /nowy import|new import/i })
    .first()
    .click();
  await expect(page).toHaveURL(/\/integrations\/imports\/new$/);
});
