import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2942 — views that ask for the whole collection (`pagination: { mode:
 * 'off' }`) were silently truncated. Refine still fills its defaults on an
 * unpaginated query (`currentPage: 1, pageSize: 10`) and the data provider
 * forwarded them as `itemsPerPage=10`, so API Platform returned one short
 * page. The operator lost first-level categories from the tree and from the
 * parent picker, and saw 8 of the 12 attributes they had just created.
 *
 * Both assertions compare the rendered control against the collection count
 * the API reports, so they stay honest as the demo dataset grows — a
 * hard-coded ">10" would start passing again the day the seed shrinks.
 */
test('unpaginated views render the whole collection, not the first page', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const { productTypeId, categoryCount, attributeCount } = await page.evaluate(async () => {
    const refresh = await fetch('/api/auth/refresh', {
      method: 'POST',
      headers: { accept: 'application/json' },
    });
    const { token } = (await refresh.json()) as { token: string };
    const bearer = { accept: 'application/ld+json', authorization: `Bearer ${token}` };

    const types = (await (
      await fetch('/api/object_types?itemsPerPage=200', { headers: bearer })
    ).json()) as { member?: Array<{ id: string; kind: string; codeImmutable: boolean }> };
    const product = (types.member ?? []).find((t) => t.kind === 'product' && t.codeImmutable);
    if (product === undefined) throw new Error('Built-in product ObjectType not seeded.');

    const categories = (await (
      await fetch(`/api/categories?categoryTargetObjectType=${product.id}&itemsPerPage=300`, {
        headers: bearer,
      })
    ).json()) as { totalItems?: number };
    const attributes = (await (
      await fetch('/api/attributes?itemsPerPage=300', { headers: bearer })
    ).json()) as { totalItems?: number };

    return {
      productTypeId: product.id,
      categoryCount: categories.totalItems ?? 0,
      attributeCount: attributes.totalItems ?? 0,
    };
  });

  // The bug only shows on a collection larger than one default page; a demo
  // seed below that threshold would make this spec vacuous.
  expect(categoryCount).toBeGreaterThan(10);
  expect(attributeCount).toBeGreaterThan(10);

  // Parent picker — the control the operator watched a root category vanish
  // from. One extra <option> is the "root (brak)" placeholder.
  await page.goto(`/modeling/categories/new?targetObjectTypeId=${productTypeId}`);
  const parentOptions = page.locator('#cat-parent option');
  await expect(parentOptions).toHaveCount(categoryCount + 1, { timeout: 15_000 });

  // Attribute library — every row links to its detail page, so the anchors
  // count the rendered set once the sibling links are excluded (the "new"
  // CTA, and the per-row "values" shortcut that select/multiselect rows
  // carry). Audit attributes are hidden from the default chip, hence "at
  // most the collection, and more than one page".
  await page.goto('/modeling/attributes');
  const rows = page.locator(
    'a[href^="/modeling/attributes/"]:not([href$="/new"]):not([href$="/values"])',
  );
  await expect(rows.first()).toBeVisible({ timeout: 15_000 });
  const rendered = await rows.count();
  expect(rendered).toBeGreaterThan(10);
  expect(rendered).toBeLessThanOrEqual(attributeCount);
});
