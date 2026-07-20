import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2669 — redundant in-page headers removed (topbar breadcrumb carries the
 * context) and the Modeling shell adopted the shared v2 chrome:
 *
 *  1. /products — no "Workspace · katalog" eyebrow, no h1, no "SKU · last
 *     sync" counter; the toolbar is the first thing the list renders.
 *  2. /modeling — no "Modelowanie" header; the 6 tabs render as PillTabs
 *     (same pattern as the Exports hub) and still navigate + highlight.
 *  3. Modeling sub-pages keep their own header whose CTA now uses the
 *     standard orange `bg-cta` styling.
 */
test('#2669 — headers removed, modeling tabs as pills, orange CTA', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
  await loginAsAdmin(page);

  // 1. Products list: header gone, toolbar still there.
  await page.goto('/products');
  await expect(
    page.getByRole('searchbox', { name: /szukaj produktów|search products/i }),
  ).toBeVisible();
  const main = page.locator('main');
  await expect(main.getByText(/Workspace · (katalog|catalog)/)).toHaveCount(0);
  await expect(main.getByRole('heading', { level: 1 })).toHaveCount(0);
  await expect(main.getByText(/ostatnia synchronizacja|last sync/i)).toHaveCount(0);

  // 2. Modeling shell: no header, pill tablist drives the route tree.
  await page.goto('/modeling');
  await expect(page).toHaveURL(/\/modeling\/object-types$/);
  await expect(
    main.getByRole('heading', { level: 1, name: /^(Modelowanie|Modeling)$/ }),
  ).toHaveCount(0);

  const tablist = page.getByRole('tablist', { name: /modeling sections|sekcje modelowania/i });
  await expect(tablist).toBeVisible();
  const objectTypesTab = tablist.getByRole('tab', {
    name: /(^|\s)(object types|typy obiektów)(\s|$)/i,
  });
  await expect(objectTypesTab).toHaveAttribute('aria-selected', 'true');
  // PillTabs active pill = navy background (bg-zinc-900), not the old
  // underline (border-b-2) tablist.
  await expect(objectTypesTab).toHaveClass(/bg-zinc-900/);

  const attributesTab = tablist.getByRole('tab', { name: /(^|\s)(attributes|atrybuty)(\s|$)/i });
  await attributesTab.click();
  await expect(page).toHaveURL(/\/modeling\/attributes$/);
  await expect(attributesTab).toHaveAttribute('aria-selected', 'true');

  // Deep-link still highlights the parent tab.
  await page.goto('/modeling/object-types');
  await expect(objectTypesTab).toHaveAttribute('aria-selected', 'true');

  // 3. Sub-page CTA uses the shared orange CTA styling.
  const cta = main.getByRole('button', { name: /utwórz custom|create custom/i }).first();
  await expect(cta).toBeVisible();
  await expect(cta).toHaveClass(/bg-cta/);
});
