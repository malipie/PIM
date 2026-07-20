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
 *  3. (#2671) Modeling sub-pages register their create CTA into the topbar
 *     action slot (left of the language switcher) with the standard orange
 *     `bg-cta` styling — no second create button inside the page content.
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

  // 3. (#2671) Create CTAs live in the topbar action slot, not in the page
  //    content, and use the shared orange bg-cta styling.
  const objectTypesCta = page.getByRole('link', { name: /utwórz custom|create custom/i });
  await expect(objectTypesCta).toBeVisible();
  await expect(objectTypesCta).toHaveClass(/bg-cta/);
  await expect(main.getByRole('link', { name: /utwórz custom|create custom/i })).toHaveCount(0);

  // Categories: exactly one plus (the icon) — label carries no "+" prefix —
  // and the tree's ObjectType filter stays in-page.
  await page.goto('/modeling/categories');
  const categoriesCta = page.getByRole('link', { name: /nowa kategoria|new category/i });
  await expect(categoriesCta).toBeVisible();
  await expect(categoriesCta).toHaveClass(/bg-cta/);
  await expect(categoriesCta).not.toContainText('+');
  await expect(main.getByRole('link', { name: /nowa kategoria|new category/i })).toHaveCount(0);

  // Attributes: topbar CTA navigates to the create form.
  await page.goto('/modeling/attributes');
  const attributesCta = page.getByRole('link', { name: /nowy atrybut|new attribute/i });
  await expect(attributesCta).toBeVisible();
  await attributesCta.click();
  await expect(page).toHaveURL(/\/modeling\/attributes\/new$/);

  // Leaving the modeling area clears the topbar slot.
  await page.goto('/dashboard');
  await expect(page.getByRole('link', { name: /nowy atrybut|new attribute/i })).toHaveCount(0);
});
