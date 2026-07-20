import AxeBuilder from '@axe-core/playwright';
import { expect, type Route, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * APIC-P4-08 (#1798) — the shared Konfigurator API shell unifies both faces.
 * All resources are mocked empty, so the test is deterministic: navigate the
 * pill tabs producer → consumer → monitor → producer and assert each face's
 * landing renders, proving cross-face navigation + no route regression.
 */
test('APIC-P4-08 — unified shell: navigate across producer / consumer / monitor', async ({
  page,
}) => {
  await loginAsAdmin(page);

  for (const res of [
    'api_profiles',
    'api_keys',
    'webhook_deliveries',
    'connections',
    'sync_runs',
    'object_types',
  ]) {
    await page.route(`**/api/${res}**`, (r: Route) =>
      r.fulfill({
        status: 200,
        contentType: 'application/ld+json',
        body: JSON.stringify({ member: [], totalItems: 0 }),
      }),
    );
  }

  // #2576 — the configurator landing redirects to the Połączenia (connections)
  // tab, the configured first face.
  await page.goto('/integrations/api-configurator');
  await expect(page).toHaveURL(/\/connections$/);
  await expect(page.getByRole('heading', { name: /^połączenia$|^connections$/i })).toBeVisible();

  // a11y on the unified shell at rest (each face is audited in its own E2E;
  // here we check the shell landing before exercising cross-face navigation).
  // `.exclude('.bg-cta')` — orange topbar CTA (#2671), sanctioned design
  // accent excluded across a11y specs (same as nui-13 / exr-16).
  const a11y = await new AxeBuilder({ page }).exclude('.bg-cta').analyze();
  expect(a11y.violations).toEqual([]);

  // → Producer face (Moje API), now on its own /producer path.
  await page.getByRole('tab', { name: /^moje api$|^my api$/i }).click();
  await expect(page).toHaveURL(/\/producer$/);
  await expect(page.getByRole('heading', { name: /moje api|my api/i })).toBeVisible();

  // → Monitor.
  await page.getByRole('tab', { name: /^monitor$/i }).click();
  await expect(page).toHaveURL(/\/monitor$/);
  await expect(
    page.getByRole('heading', { name: /monitor synchronizacji|sync monitor/i }),
  ).toBeVisible();

  // → back to Połączenia.
  await page.getByRole('tab', { name: /^połączenia$|^connections$/i }).click();
  await expect(page).toHaveURL(/\/connections$/);
  await expect(page.getByRole('heading', { name: /^połączenia$|^connections$/i })).toBeVisible();
});
