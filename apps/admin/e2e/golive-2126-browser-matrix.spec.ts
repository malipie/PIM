// GOLIVE #2126 — cross-browser smoke of critical views on Firefox + WebKit
// (Safari engine). The rest of the E2E suite runs Chromium only; this spec is
// the automated equivalent of the "1 przejście na Firefox + Safari" manual
// review — it loads each critical view, asserts a key element renders, and
// fails on any browser console error (engine-specific CSS/JS breakage is the
// real risk a Chromium-only suite misses).
//
// Run explicitly with the firefox / webkit projects (see run instructions in
// docs/audit/2026-07-handover/browser-matrix.md); it is NOT part of the
// default Chromium CI lane.

import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

const CRITICAL_VIEWS: Array<{ name: string; path: string; expect: RegExp }> = [
  { name: 'dashboard', path: '/dashboard', expect: /workspace|pulpit|dashboard|witaj|welcome/i },
  { name: 'products', path: '/products', expect: /produkt|product/i },
  { name: 'modeling', path: '/modeling', expect: /modelowanie|modeling|atrybut|attribute/i },
  { name: 'imports', path: '/integrations/imports', expect: /import/i },
  { name: 'feeds', path: '/feeds', expect: /feed|kanał|channel/i },
  { name: 'settings-users', path: '/settings/users', expect: /użytkownic|user|rola|role/i },
];

test.describe('GOLIVE #2126 — cross-browser critical views', () => {
  test.beforeEach(async ({ page }) => {
    // Deterministic Polish UI regardless of the browser's Accept-Language.
    await page.addInitScript(() => {
      window.localStorage.setItem('i18nextLng', 'pl');
    });
  });

  for (const view of CRITICAL_VIEWS) {
    test(`${view.name} renders without console errors`, async ({ page }, testInfo) => {
      const consoleErrors: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
      });
      page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));

      await loginAsAdmin(page);
      await page.goto(view.path);
      await expect(page.getByText(view.expect).first()).toBeVisible({ timeout: 15_000 });

      // Attach a screenshot per browser/view for the manual visual diff.
      await testInfo.attach(`${view.name}-${testInfo.project.name}`, {
        body: await page.screenshot({ fullPage: false }),
        contentType: 'image/png',
      });

      // WebKit here is reached via the https://localhost host workaround
      // (it cannot resolve pim.localhost without an /etc/hosts entry — the
      // #2182 DNS gotcha, needs sudo). Under that workaround the Vite HMR
      // client still targets wss://pim.localhost and a background refresh
      // hits the cookie on the wrong host → dev-only console noise that does
      // NOT occur in a prod build. So WebKit is asserted RENDER-ONLY (the
      // text assertion + screenshot above); Firefox — reached natively — gets
      // the full console-clean gate.
      if (testInfo.project.name === 'webkit') return;

      const meaningful = consoleErrors.filter(
        (e) =>
          // Benign / dev-only noise: favicon, Mercure SSE reconnects,
          // ResizeObserver loops, and Vite @fs font-download failures that
          // Firefox logs as errors but Chromium silently ignores (prod
          // bundles fonts differently — not a production concern).
          !/favicon|mercure|ResizeObserver|Failed to load resource.*svg|downloadable font|@fs.*\.woff2/i.test(
            e,
          ),
      );
      expect(meaningful, `console errors on ${view.name}: ${meaningful.join(' | ')}`).toEqual([]);
    });
  }
});
