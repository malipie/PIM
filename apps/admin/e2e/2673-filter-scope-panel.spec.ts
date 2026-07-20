import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2673 — value-context scope (Kanał / Język) in the advanced filter panel:
 *
 *  1. The panel renders the context bar with channel + locale selects.
 *  2. Picking a locale and applying puts the scope chip in the chips bar
 *     and `fscope[...]` params ride the search request blob (the request
 *     goes to `/api/search/*` with a base64 DSL carrying the scope).
 *  3. The chip's ✕ clears the context.
 */
test('#2673 — filter panel scope: bar, apply, chip, clear', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
  await loginAsAdmin(page);
  await page.goto('/products');

  // Open the advanced filter panel.
  await page.getByRole('button', { name: /filtruj zaawansowane|advanced filter/i }).click();
  const panel = page.getByRole('region', { name: /filtr zaawansowany|advanced filter/i });
  await expect(panel).toBeVisible();

  // Context bar renders with both selects defaulting to global.
  const channelSelect = panel.getByLabel(/kanał kontekstu|filter context channel/i);
  const localeSelect = panel.getByLabel(/język kontekstu|filter context locale/i);
  await expect(channelSelect).toBeVisible();
  await expect(localeSelect).toBeVisible();
  await expect(channelSelect).toHaveValue('');
  await expect(localeSelect).toHaveValue('');

  // Pick a locale (demo tenant always has at least `pl`), add a condition
  // and apply.
  await localeSelect.selectOption('pl');
  await panel.getByRole('button', { name: /dodaj warunek|add condition/i }).click();

  const scopedSearch = page.waitForRequest(
    (request) => {
      if (!request.url().includes('/api/search/')) return false;
      const url = new URL(request.url());
      const blob = url.searchParams.get('q');
      if (!blob) return false;
      try {
        return JSON.parse(atob(blob)).scope?.locale === 'pl';
      } catch {
        return false;
      }
    },
    { timeout: 15_000 },
  );
  await panel.getByRole('button', { name: /zastosuj filtr|apply filter/i }).click();
  await scopedSearch;

  // Scope chip appears in the chips bar; ✕ clears the context.
  const chipsBar = page.getByRole('region', { name: /aktywne filtry|active filters/i });
  await expect(chipsBar.getByText(/^PL$/)).toBeVisible();
  await chipsBar.getByRole('button', { name: /wyczyść kontekst|clear context/i }).click();
  await expect(chipsBar.getByText(/^PL$/)).toHaveCount(0);
});
