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
 * The spec seeds its own rows rather than leaning on the demo catalogue: the
 * truncation only shows above the default page size, and the fixtures carry
 * 5 categories — which would make every assertion here pass either way.
 */

const SEEDED = 12;

test('unpaginated views render the whole collection, not the first page', async ({ page }) => {
  test.setTimeout(180_000);

  await loginAsAdmin(page);

  const run = `e2e2942${Date.now().toString(36)}`;
  const seeded = await page.evaluate(
    async ([prefix, countRaw]) => {
      const count = Number(countRaw);
      const refresh = await fetch('/api/auth/refresh', {
        method: 'POST',
        headers: { accept: 'application/json' },
      });
      const { token } = (await refresh.json()) as { token: string };
      const read = { accept: 'application/ld+json', authorization: `Bearer ${token}` };
      const write = { ...read, 'content-type': 'application/ld+json' };

      const types = (await (
        await fetch('/api/object_types?itemsPerPage=200', { headers: read })
      ).json()) as { member?: Array<{ id: string; kind: string; codeImmutable: boolean }> };
      const builtIn = (kind: string) =>
        (types.member ?? []).find((t) => t.kind === kind && t.codeImmutable);
      const product = builtIn('product');
      const category = builtIn('category');
      if (product === undefined || category === undefined) {
        throw new Error('Built-in product/category ObjectTypes not seeded.');
      }

      const categoryIds: string[] = [];
      const attributeIds: string[] = [];
      for (let i = 0; i < count; i += 1) {
        const suffix = String(i).padStart(2, '0');
        const createdCategory = (await (
          await fetch('/api/categories', {
            method: 'POST',
            headers: write,
            body: JSON.stringify({
              code: `${prefix}_c${suffix}`,
              objectTypeId: category.id,
              categoryTargetObjectTypeId: product.id,
              attributes: { name: `E2E 2942 kategoria ${suffix}` },
            }),
          })
        ).json()) as { id?: string };
        if (createdCategory.id !== undefined) categoryIds.push(createdCategory.id);

        const createdAttribute = (await (
          await fetch('/api/attributes', {
            method: 'POST',
            headers: write,
            body: JSON.stringify({
              code: `${prefix}_a${suffix}`,
              label: { pl: `E2E 2942 atrybut ${suffix}`, en: `E2E 2942 attribute ${suffix}` },
              type: 'text',
            }),
          })
        ).json()) as { id?: string };
        if (createdAttribute.id !== undefined) attributeIds.push(createdAttribute.id);
      }

      const categories = (await (
        await fetch(`/api/categories?categoryTargetObjectType=${product.id}&itemsPerPage=500`, {
          headers: read,
        })
      ).json()) as { totalItems?: number };

      return {
        productTypeId: product.id,
        categoryIds,
        attributeIds,
        categoryTotal: categories.totalItems ?? 0,
      };
    },
    [run, String(SEEDED)] as const,
  );

  try {
    expect(seeded.categoryIds).toHaveLength(SEEDED);
    expect(seeded.attributeIds).toHaveLength(SEEDED);
    // Guard against a vacuous run: below the default page size the truncation
    // this spec pins cannot show up at all.
    expect(seeded.categoryTotal).toBeGreaterThan(10);

    // Parent picker — the control the operator watched a root category vanish
    // from. One extra <option> is the "root (brak)" placeholder.
    await page.goto(`/modeling/categories/new?targetObjectTypeId=${seeded.productTypeId}`);
    await expect(page.locator('#cat-parent option')).toHaveCount(seeded.categoryTotal + 1, {
      timeout: 15_000,
    });

    // Attribute library — "I added 12, the list showed 8". The search box
    // filters the set the page already holds, so a truncated fetch cannot be
    // rescued by narrowing: all 12 have to be loaded to be found.
    await page.goto('/modeling/attributes');
    await page.getByPlaceholder(/szukaj po code|search by code/i).fill(run);
    const rows = page.locator(
      'a[href^="/modeling/attributes/"]:not([href$="/new"]):not([href$="/values"])',
    );
    await expect(rows).toHaveCount(SEEDED, { timeout: 15_000 });
  } finally {
    await page.evaluate(
      async ([categoryIds, attributeIds]) => {
        const refresh = await fetch('/api/auth/refresh', {
          method: 'POST',
          headers: { accept: 'application/json' },
        });
        const { token } = (await refresh.json()) as { token: string };
        const headers = { authorization: `Bearer ${token}` };
        for (const id of categoryIds) {
          await fetch(`/api/categories/${id}`, { method: 'DELETE', headers });
        }
        for (const id of attributeIds) {
          await fetch(`/api/attributes/${id}`, { method: 'DELETE', headers });
        }
      },
      [seeded.categoryIds, seeded.attributeIds] as const,
    );
  }
});
