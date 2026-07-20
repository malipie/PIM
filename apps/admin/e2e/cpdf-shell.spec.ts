import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CPDF-P5-01 (#2372) — PDF catalogs area shell: the `/catalogs-pdf` route now
 * renders a self-contained PillTabs hub (Katalogi / Szablony) replacing the
 * ComingSoonPlaceholder. The catalogs tab lands on the empty state + "Nowy
 * katalog" CTA (wizard is P5-02); the templates tab lists the built-in `sheet`
 * archetype as available with `grid`/`pricelist` as "Wkrótce".
 *
 * `GET /api/catalogs` is mocked to `{ items: [] }` so the shell is
 * deterministic and offline. The known DNS/lang gotcha: the app renders EN
 * from the user profile unless `i18nextLng=pl` is seeded before the app boots.
 */

test('CPDF-P5-01 — catalogs shell: tabs, empty state, templates, a11y', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
  await page.route('**/api/catalogs', (route) => route.fulfill({ json: { items: [] } }));

  await loginAsAdmin(page);
  await page.goto('/catalogs-pdf');
  // Freeze CSS transitions before the axe scan: the pill-tab color
  // transition (150ms) otherwise races the contrast check — axe samples
  // mid-transition colors (e.g. #616f85 on #d0d4dc) and reports false
  // color-contrast violations (#2669).
  await page.addStyleTag({
    content: '*, *::before, *::after { transition: none !important; animation: none !important; }',
  });

  // Both pill tabs render (bilingual-tolerant) — the "Web To Print" page
  // header was removed in #2669 (topbar breadcrumb names the area).
  const catalogsTab = page.getByRole('tab', { name: /^katalogi$|^catalogs$/i });
  const templatesTab = page.getByRole('tab', { name: /^szablony$|^templates$/i });
  await expect(catalogsTab).toBeVisible();
  await expect(templatesTab).toBeVisible();

  // Catalogs tab (default): empty state + "Nowy katalog" CTA.
  await expect(page.getByText(/brak katalogów|no catalogs yet/i)).toBeVisible();
  await expect(
    page.getByRole('button', { name: /nowy katalog|new catalog/i }).first(),
  ).toBeVisible();

  // Switch to the Szablony tab — the sheet archetype is available.
  await templatesTab.click();
  await expect(page.getByRole('radio', { name: /karta produktu|product sheet/i })).toBeVisible();
  // Grid/pricelist are selectable in the wizard (#2568) but stay muted in this
  // informational shell tab.
  await expect(
    page.getByRole('radio', { name: /katalog \(grid\)|catalog \(grid\)/i }),
  ).toHaveAttribute('aria-disabled', 'true');

  // a11y — no serious/critical violations on the shell's main content.
  const axe = await new AxeBuilder({ page }).include('main').analyze();
  const blocking = axe.violations.filter(
    (violation) => violation.impact === 'serious' || violation.impact === 'critical',
  );
  expect(blocking).toEqual([]);
});
