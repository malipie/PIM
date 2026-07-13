import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { apiLogin } from './helpers/auth';

/**
 * EXR-16 (#1392) — axe-core gate (WCAG A/AA) over the exports redesign:
 * sessions page, all four wizard steps, profiles tab. Data mocked so the
 * scan sees fully-rendered states.
 */

async function expectNoViolations(page: Page) {
  // Let CSS transitions finish — axe samples computed colors and a
  // mid-transition tile (navy → emerald) reads as an illegible blend.
  await page.waitForTimeout(350);
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa'])
    // The scan covers the exports surface; the global shell (sidebar,
    // topbar) is included implicitly and must stay clean too.
    // Exclude CTA buttons: the brand orange (#ff4f00) is an accepted
    // sub-AA-contrast exception per the operator's design system.
    .exclude('.bg-cta')
    .analyze();
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

const SESSION = {
  id: '00000000-0000-0000-0000-000000000001',
  entity_type: 'product',
  object_type_id: null,
  format: 'xlsx',
  target_scope: 'all',
  target_count: 100,
  success_count: 100,
  status: 'done',
  source: 'central_tab',
  started_at: new Date().toISOString(),
  completed_at: new Date().toISOString(),
  profile_name: null,
  file_path: 'exports/a11y.xlsx',
  duration_ms: 1000,
  error_message: null,
};

test.beforeEach(async ({ page }) => {
  // Color-contrast sampling races CSS transitions (axe reads a mid-blend
  // of the stepper tile animating navy → emerald). Standard practice for
  // a11y scans: freeze animations/transitions for the whole run.
  await page.addInitScript(() => {
    const style = document.createElement('style');
    style.textContent =
      '*, *::before, *::after { transition: none !important; animation: none !important; }';
    document.documentElement.appendChild(style);
  });
  await page.route('**/api/exports/sessions', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [SESSION], total: 1 }),
    }),
  );
  await page.route('**/api/exports/profiles', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [], total: 0 }),
    }),
  );
  await page.route('**/api/exports/preflight', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        count: 5,
        mode: 'sync',
        threshold: 100,
        soft_cap: 100000,
        exceeds_cap: false,
      }),
    }),
  );
  await page.route('**/api/attributes?*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify([{ id: 'a1', code: 'brand', label: { pl: 'Marka' }, type: 'relation' }]),
    }),
  );
  for (const url of ['**/api/attribute_groups?*', '**/api/channels?*'] as const) {
    await page.route(url, (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }),
    );
  }
  await page.route('**/api/workspaces/current', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ enabledLocales: ['pl'], primaryLocale: 'pl' }),
    }),
  );
  await apiLogin(page);
  await page.waitForTimeout(1200);
});

test('a11y: sessions page', async ({ page }) => {
  await page.goto('/integrations/exports/sessions', { waitUntil: 'commit' });
  await expect(page.getByText('a11y.xlsx')).toBeVisible();
  await expectNoViolations(page);
});

test('a11y: profiles tab (empty state)', async ({ page }) => {
  await page.goto('/integrations/exports/profiles', { waitUntil: 'commit' });
  await expect(page.getByText(/Brak zapisanych profili|No saved profiles/)).toBeVisible();
  await expectNoViolations(page);
});

test('a11y: wizard steps 1-4', async ({ page }) => {
  await page.goto('/integrations/exports/new', { waitUntil: 'commit' });
  await expect(page.getByRole('radiogroup')).toBeVisible();
  await expectNoViolations(page); // step 1

  await page.getByRole('button', { name: /Dalej|Next/ }).click();
  await expect(page.getByTestId('preflight-badge')).toBeVisible();
  await expectNoViolations(page); // step 2

  await page.getByRole('button', { name: /Dalej|Next/ }).click();
  await expect(page.getByText(/Dostępne atrybuty|Available attributes/)).toBeVisible();
  await expectNoViolations(page); // step 3

  await page.getByRole('button', { name: /Dalej|Next/ }).click();
  await expect(page.getByText(/Podsumowanie konfiguracji|Configuration summary/)).toBeVisible();
  await expectNoViolations(page); // step 4
});
