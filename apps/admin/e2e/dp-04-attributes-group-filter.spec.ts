import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-04 (#2034) — attributes list filters by AttributeGroup.
 *
 * Single test, one login (rate-limiter budget). The dropdown drives the
 * server-side `?attributeGroup=` filter (AttributeGroupFilter) and keeps
 * its state in the URL (`?group=`), so refresh preserves the narrowed view.
 */
test('DP-04 — attributes list narrows by attribute group and keeps state in URL', async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.goto('/modeling/attributes');

  // Baseline: count the rendered library rows (the "{N} atrybutów" caption
  // was removed in #2671 — rows are detail links inside main; the topbar
  // "Nowy atrybut" CTA lives outside main so it does not inflate the count).
  const rows = page.locator('main').locator('a[href^="/modeling/attributes/"]');
  const readCount = (): Promise<number> => rows.count();
  await expect.poll(readCount).toBeGreaterThan(0);
  const before = await readCount();

  // Pick a group in the combobox (custom component: trigger button showing
  // the placeholder + a dropdown of option buttons). Demo DB seeds several
  // groups; if the environment has none the dropdown reports empty — bail.
  const trigger = page.getByRole('button', { name: /grupa atrybutów|attribute group/i });
  await expect(trigger).toBeVisible();
  await trigger.click();
  const options = page.locator('.max-h-60 > button');
  const optionCount = await options
    .first()
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => options.count())
    .catch(() => 0);
  test.skip(optionCount === 0, 'No attribute groups in this environment seed');

  await options.first().click();

  // URL carries the group id; the list narrows server-side.
  await expect(page).toHaveURL(/group=[0-9a-f-]{36}/);
  await expect.poll(readCount).toBeLessThan(before);

  // Refresh keeps the filter (URL is the source of truth).
  await page.reload();
  await expect.poll(readCount).toBeLessThan(before);
});
