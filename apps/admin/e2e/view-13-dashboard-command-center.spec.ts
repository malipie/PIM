import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-13 (#2143) — dashboard "command center" redesign, static mock.
 *
 * Asserts the pixel-perfect layout renders end-to-end: greeting + dark agent
 * hero, the four pinned KPI tiles, catalog health card (ring + buckets +
 * per-channel completeness), team activity card (range toggle + most edited)
 * and the full-width action center. Also locks in the operator decisions:
 * no MOCK badges anywhere on the page and no audit-log pill in the topbar.
 */

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

  await loginAsAdmin(page);
  await page.goto('/dashboard');

  // 1. Greeting — two-tone headline.
  await expect(
    page.getByRole('heading', { level: 1, name: /Centrum dowodzenia katalogiem/ }),
  ).toBeVisible();
  await expect(page.getByText(/Dzień dobry, Kasiu/)).toBeVisible();

  // 2. Agent hero — prompt accepts focus and typing, nothing is submitted.
  const prompt = page.getByRole('textbox', { name: /Polecenie dla agenta/i });
  await expect(prompt).toHaveValue(/stwórz feed XML dla Google Shopping/);
  await prompt.click();
  await prompt.fill('test polecenia');
  await expect(prompt).toHaveValue('test polecenia');
  // exact — the sidebar search trigger is also named "Zapytaj agenta lub szukaj...".
  await expect(page.getByRole('button', { name: 'Zapytaj agenta', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Generuj opisy SEO' })).toBeVisible();
  await expect(page.getByText('42 zaakceptowanych zmian w tym tygodniu')).toBeVisible();

  // 3. KPI band — the four pinned tiles with exact mock values.
  // ("10 984" also renders inside the health card, hence .first()).
  await expect(page.getByText('12 847', { exact: true })).toBeVisible();
  await expect(page.getByText('10 984', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('87%', { exact: true })).toBeVisible();
  await expect(page.getByText('24h · brak trendu')).toBeVisible();

  // 4. Catalog health — ring caption, bucket legend, worst-first channels.
  await expect(page.getByRole('heading', { name: 'Kompletność katalogu' })).toBeVisible();
  await expect(page.getByText('gotowe do publikacji', { exact: true })).toBeVisible();
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
});

test('VIEW-13 — dashboard passes the axe-core WCAG A/AA scan', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/dashboard');
  await expect(page.getByRole('heading', { name: 'Centrum akcji' })).toBeVisible();
  await expectNoViolations(page);
});
