import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * VIEW-13 (#2143) — dashboard "command center" redesign.
 *
 * Asserts the layout renders end-to-end against LIVE data (epic DASH,
 * #2249–#2274): greeting + dark agent hero (chips from
 * /api/agent/capabilities, stubbed here for determinism), the four KPI
 * tiles + catalog health (GET /api/dashboard/summary), team activity
 * (range toggle → /activity + /top-edited) and the action center
 * (/alerts). Assertions are structural (numbers/hrefs/empty states), not
 * pinned mock literals, so they hold as the demo catalog changes. Also
 * locks in the operator decisions: no MOCK badges anywhere on the page
 * and no audit-log pill in the topbar.
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

test('VIEW-13 — command center renders all five sections with live data', async ({ page }) => {
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

  // 3. KPI band — ALL four tiles read GET /api/dashboard/summary since
  // DASH-06 (#2259): structural assertions + drill-down hrefs. The alerts
  // tile shows "—" until the alert aggregator lands (DASH-10).
  const main = page.locator('main');
  const productsTile = main.getByRole('link', { name: /łącznie w katalogu/ });
  await expect(productsTile).toBeVisible();
  await expect(productsTile).toHaveAttribute('href', '/products');
  await expect(productsTile.locator('.num').first()).toHaveText(/^[\d\s ]+$/);
  const readyTile = main.getByRole('link', { name: /Gotowe do publikacji/ });
  await expect(readyTile).toHaveAttribute('href', /completeness_pct/);
  await expect(readyTile.locator('.num').first()).toHaveText(/^[\d\s ]+$/);
  // DASH-06: the avg tile is live too — structural instead of the mock 87%.
  const avgTile = main.getByRole('link', { name: /Średnia kompletność/ });
  await expect(avgTile.locator('.num').first()).toHaveText(/^\d+%$/);
  // Products delta is real from day one (created-in-30d); completeness
  // deltas render "brak trendu" until the snapshot horizons exist.
  await expect(productsTile.getByText(/^[+−±]/)).toBeVisible();
  await expect(page.getByText('24h · brak trendu')).toBeVisible();
  await expect(main.getByRole('link', { name: /Otwarte alerty/ })).toHaveAttribute(
    'href',
    '#action-center',
  );

  // 4. Catalog health — fully live since DASH-06: ring + ready line,
  // clickable bucket legend, per-channel rows straight from the aggregate
  // (or the empty state — CI has no channels with completeness data). The
  // weekly-trend badge renders only when the 7d snapshot horizon exists,
  // so assert its format only when present.
  await expect(page.getByRole('heading', { name: 'Kompletność katalogu' })).toBeVisible();
  await expect(page.getByText('gotowe do publikacji', { exact: true })).toBeVisible();
  await expect(page.getByText(/SKU ≥ 80%/)).toBeVisible();
  const weeklyBadge = page.getByText(/pkt \/ tydz\./);
  if ((await weeklyBadge.count()) > 0) {
    await expect(weeklyBadge.first()).toHaveText(/\d+ pkt \/ tydz\./);
  }
  expect(await page.getByRole('link', { name: /Pokaż produkty o kompletności/ }).count()).toBe(5);
  await expect(page.getByText('Kompletność wg kanału')).toBeVisible();
  const channelRows = page.getByRole('link', { name: /Pokaż produkty poniżej progu/ });
  const channelsEmpty = page.getByText(/Brak kanałów z danymi kompletności/);
  expect((await channelRows.count()) > 0 || (await channelsEmpty.count()) === 1).toBe(true);

  // 5. Team activity — live since DASH-08: the range toggle persists in
  // the URL and reloads the series from the API; the ranking renders live
  // rows or the honest empty state (fresh stacks carry no product edits).
  await expect(page.getByRole('heading', { name: 'Tempo pracy zespołu' })).toBeVisible();
  const range7 = page.getByRole('button', { name: '7 dni' });
  await expect(range7).toHaveAttribute('aria-pressed', 'false');
  const activityReload = page.waitForResponse(
    (res) => res.url().includes('/api/dashboard/activity') && res.url().includes('range=7d'),
    { timeout: 15_000 },
  );
  await range7.click();
  await expect(range7).toHaveAttribute('aria-pressed', 'true');
  await expect(page).toHaveURL(/range=7d/);
  expect((await activityReload).status()).toBe(200);
  await expect(page.getByText('Najczęściej edytowane')).toBeVisible();
  const topRows = main.getByRole('link', { name: /edycji/ });
  const topEmpty = page.getByText('Brak edycji produktów w tym okresie.');
  expect((await topRows.count()) > 0 || (await topEmpty.count()) === 1).toBe(true);

  // 6. Action center — live since DASH-10: real alerts from
  // GET /api/dashboard/alerts (live rows with per-type CTAs + ack) or the
  // positive "Wszystko działa ✓" empty state. The header renders either
  // way (section never hidden, brief §5-B).
  await expect(page.getByRole('heading', { name: 'Centrum akcji' })).toBeVisible();
  await expect(page.getByText(/\d+ spraw/)).toBeVisible();
  const alertAcks = page.getByText('oznacz jako przeczytane');
  const allClear = page.getByText('Wszystko działa ✓');
  expect((await alertAcks.count()) > 0 || (await allClear.count()) === 1).toBe(true);

  // Operator decisions: zero MOCK badges in the dashboard content (the
  // sidebar agent-search badge is out of scope), no audit pill in the topbar.
  await expect(page.locator('main').getByText('MOCK', { exact: true })).toHaveCount(0);
  await expect(page.getByText(/Audit log/)).toHaveCount(0);

  // No red console errors (network noise excluded — live-data page).
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
