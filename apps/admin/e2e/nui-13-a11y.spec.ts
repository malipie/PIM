import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

import { apiLogin } from './helpers/auth';

/**
 * NUI-13 (#1432) — axe-core gate (WCAG A/AA, serious+critical) over the
 * NUI epic surfaces: dashboard, products list, modeling tabs, assets
 * explorer, imports hub + wizard, settings users/roles. Complements the
 * EXR-16 gate that covers exports. Live pages (no route mocks) — the
 * scan tolerates empty fixture states; component markup is what counts.
 */

const VIEWS: ReadonlyArray<{ path: string; ready: RegExp }> = [
  { path: '/dashboard', ready: /produkty|products/i },
  { path: '/products', ready: /sku/i },
  { path: '/modeling/object-types', ready: /object types/i },
  { path: '/modeling/attributes', ready: /atrybut|attribute/i },
  { path: '/modeling/attribute-groups', ready: /grup|group/i },
  { path: '/assets', ready: /pliki|files/i },
  { path: '/integrations/imports/sessions', ready: /sesj|session/i },
  { path: '/integrations/imports/new', ready: /import/i },
  { path: '/settings/users', ready: /użytkownic|users/i },
  { path: '/settings/roles', ready: /role/i },
];

async function expectNoSeriousViolations(page: Page) {
  await page.waitForTimeout(400);
  // Exclude CTA buttons: the brand orange (#ff4f00) is an accepted
  // sub-AA-contrast exception per the operator's design system.
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa'])
    .exclude('.bg-cta')
    .analyze();
  const serious = results.violations.filter(
    (violation) => violation.impact === 'serious' || violation.impact === 'critical',
  );
  expect(
    serious.flatMap((violation) =>
      violation.nodes.slice(0, 3).map((node) => `${violation.id} @ ${node.target.join(' ')}`),
    ),
  ).toEqual([]);
}

test.beforeEach(async ({ page }) => {
  // Freeze transitions — axe samples computed colors mid-animation.
  await page.addInitScript(() => {
    const style = document.createElement('style');
    style.textContent =
      '*, *::before, *::after { transition: none !important; animation: none !important; }';
    document.documentElement.appendChild(style);
  });
  await apiLogin(page);
  // EXR-16 pattern — let the token land before the first authed render.
  await page.waitForTimeout(1200);
});

for (const view of VIEWS) {
  test(`a11y: ${view.path}`, async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto(view.path, { waitUntil: 'commit' });
    await expect(page.getByText(view.ready).first()).toBeVisible({ timeout: 20_000 });
    await expectNoSeriousViolations(page);
  });
}
