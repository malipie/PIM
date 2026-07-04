import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-13 (#2143) — dashboard "command center" redesign.
 *
 * Asserts the pixel-perfect layout renders end-to-end: greeting + dark agent
 * hero (LIVE since #2246 — chips come from /api/agent/capabilities, stubbed
 * here for determinism), the four pinned KPI tiles, catalog health card
 * (ring + buckets + per-channel completeness), team activity card (range
 * toggle + most edited) and the full-width action center. Also locks in the
 * operator decisions: no MOCK badges anywhere on the page and no audit-log
 * pill in the topbar.
 */

const CAPABILITIES_STUB = {
  enabled: true,
  reason: null,
  actions: [
    {
      id: 'create_update_attribute',
      label: { pl: 'Dodaj atrybut', en: 'Add attribute' },
      prompt: { pl: 'Dodaj atrybut [nazwa]', en: 'Add attribute [name]' },
    },
    {
      id: 'generate_feed',
      label: { pl: 'Eksport feed XML', en: 'Export XML feed' },
      prompt: { pl: 'Wygeneruj feed XML', en: 'Generate the XML feed' },
    },
  ],
};

async function stubCapabilities(page: Page) {
  await page.route('**/api/agent/capabilities', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(CAPABILITIES_STUB),
    }),
  );
}

async function expectNoViolations(page: Page) {
  // Let CSS transitions settle before axe samples computed colors.
  await page.waitForTimeout(350);
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(
    results.violations.flatMap((violation) =>
      violation.nodes
        .slice(0, 4)
        .map(
          (node) =>
            `${violation.id} @ ${node.target.join(' ')} :: ${node.failureSummary?.split('\n')[1]?.trim() ?? ''}`,
        ),
    ),
  ).toEqual([]);
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    // The assertions pin the Polish copy of the approved mock — seed the
    // i18next detector cache so the app resolves to `pl` regardless of the
    // Playwright profile locale (default en-US renders the EN translations).
    window.localStorage.setItem('i18nextLng', 'pl');
    // Freeze animations so the axe color-contrast pass never samples a
    // mid-transition blend (same approach as exr-16-a11y).
    const style = document.createElement('style');
    style.textContent =
      '*, *::before, *::after { transition: none !important; animation: none !important; }';
    document.documentElement.appendChild(style);
  });
});

test('VIEW-13 — command center renders all five sections with mock values', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text());
    }
  });

  await stubCapabilities(page);
  await loginAsAdmin(page);
  await page.goto('/dashboard');

  // 1. Greeting — two-tone headline (the "Dzień dobry" hello line was
  // dropped per operator decision 2026-07-03).
  await expect(
    page.getByRole('heading', { level: 1, name: /Centrum dowodzenia katalogiem/ }),
  ).toBeVisible();
  await expect(page.getByText(/Dzień dobry/)).toHaveCount(0);

  // 2. Agent hero — LIVE since #2246: the prompt starts empty (the mock's
  // prefilled example moved to the placeholder), chips render from the
  // stubbed capabilities, and the weekly-stats counter is gone.
  const prompt = page.getByRole('textbox', { name: /Polecenie dla agenta/i });
  await expect(prompt).toHaveValue('');
  await expect(prompt).toHaveAttribute('placeholder', /stwórz feed XML dla Google Shopping/);
  await prompt.click();
  await prompt.fill('test polecenia');
  await expect(prompt).toHaveValue('test polecenia');
  // exact — the sidebar search trigger is also named "Zapytaj agenta lub szukaj...".
  await expect(page.getByRole('button', { name: 'Zapytaj agenta', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Dodaj atrybut', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Eksport feed XML', exact: true })).toBeVisible();
  await expect(page.getByText(/zaakceptowanych zmian w tym tygodniu/)).toHaveCount(0);

  // 3. KPI band — products & publish-ready are LIVE since DASH-02 (#2251):
  // structural assertions + drill-down hrefs instead of mock literals. The
  // avg-completeness ("87%") and alerts tiles stay on mock values until
  // their backends land (DASH-06 / DASH-10).
  const main = page.locator('main');
  const productsTile = main.getByRole('link', { name: /łącznie w katalogu/ });
  await expect(productsTile).toBeVisible();
  await expect(productsTile).toHaveAttribute('href', '/products');
  await expect(productsTile.locator('.num').first()).toHaveText(/^[\d\s ]+$/);
  const readyTile = main.getByRole('link', { name: /Gotowe do publikacji/ });
  await expect(readyTile).toHaveAttribute('href', /completeness_pct/);
  await expect(readyTile.locator('.num').first()).toHaveText(/^[\d\s ]+$/);
  await expect(page.getByText('87%', { exact: true })).toBeVisible();
  // Live tiles render the honest no-trend line (no fabricated deltas).
  await expect(page.getByText('brak trendu', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('24h · brak trendu')).toBeVisible();
  await expect(main.getByRole('link', { name: /Otwarte alerty/ })).toHaveAttribute(
    'href',
    '#action-center',
  );

  // 4. Catalog health — live ring + ready line, clickable bucket legend,
  // worst-first channels (still mock until DASH-03/06). The weekly-trend
  // badge must NOT render on live data (no aggregate yet — DASH-05).
  await expect(page.getByRole('heading', { name: 'Kompletność katalogu' })).toBeVisible();
  await expect(page.getByText('gotowe do publikacji', { exact: true })).toBeVisible();
  await expect(page.getByText(/SKU ≥ 80%/)).toBeVisible();
  await expect(page.getByText(/pkt \/ tydz\./)).toHaveCount(0);
  expect(await page.getByRole('link', { name: /Pokaż produkty o kompletności/ }).count()).toBe(5);
  await expect(page.getByText('Kompletność wg kanału')).toBeVisible();
  await expect(page.getByText('Google Shopping', { exact: true })).toBeVisible();
  await expect(page.getByText('Comarch ERP XL', { exact: true })).toBeVisible();

  // 5. Team activity — range toggle is interactive (cosmetic state only).
  await expect(page.getByRole('heading', { name: 'Tempo pracy zespołu' })).toBeVisible();
  const range7 = page.getByRole('button', { name: '7 dni' });
  await expect(range7).toHaveAttribute('aria-pressed', 'false');
  await range7.click();
  await expect(range7).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText('Najczęściej edytowane')).toBeVisible();
  await expect(page.getByText('Czujnik indukcyjny Festo IS-50 PNP M12')).toBeVisible();

  // 6. Action center — counters and all five mock items.
  await expect(page.getByRole('heading', { name: 'Centrum akcji' })).toBeVisible();
  await expect(page.getByText('5 spraw')).toBeVisible();
  await expect(page.getByText('2 krytyczne')).toBeVisible();
  await expect(page.getByText('3 ostrzeżenia')).toBeVisible();
  await expect(page.getByText(/Mtodo Marketplace/)).toBeVisible();
  await expect(page.getByText(/pim-catalog-0630\.xlsx/)).toBeVisible();
  await expect(page.getByText(/Hurtownia Stalko/)).toBeVisible();
  await expect(page.getByText(/Stal-Met/)).toBeVisible();
  expect(await page.getByText('oznacz jako przeczytane').count()).toBe(5);

  // Operator decisions: zero MOCK badges in the dashboard content (the
  // sidebar agent-search badge is out of scope), no audit pill in the topbar.
  await expect(page.locator('main').getByText('MOCK', { exact: true })).toHaveCount(0);
  await expect(page.getByText(/Audit log/)).toHaveCount(0);

  // No red console errors (network noise excluded — static mock page).
  const realErrors = consoleErrors.filter(
    (text) => !/Failed to load resource|EventSource|mercure/i.test(text),
  );
  expect(realErrors).toEqual([]);

  // Drill-down smoke (last — it navigates away): the publish-ready tile
  // lands on the products list with the completeness filter pre-seeded
  // (#2249 URL seeding).
  await readyTile.click();
  await expect(page).toHaveURL(/\/products\?filter/);
  await expect(page.getByRole('region', { name: 'Filtr zaawansowany' })).toBeVisible();
});

test('VIEW-13 — dashboard passes the axe-core WCAG A/AA scan', async ({ page }) => {
  await stubCapabilities(page);
  await loginAsAdmin(page);
  await page.goto('/dashboard');
  await expect(page.getByRole('heading', { name: 'Centrum akcji' })).toBeVisible();
  await expectNoViolations(page);
});
