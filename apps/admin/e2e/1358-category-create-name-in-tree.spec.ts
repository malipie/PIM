import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #1358 — the category tree showed the raw snake_case `code` because the
 * create form captured a name but never sent it (the VIEW-04b upserter
 * wiring was never finished). The create POST now carries
 * `attributes.name`, so a freshly-created category renders by its name in
 * the tree.
 *
 * Marked `fixme` in CI for the shared `storageState` rate-limiter reason.
 */

test('creating a category persists its name and shows it in the tree', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const types = await page.evaluate(async () => {
    const refresh = await fetch('/api/auth/refresh', {
      method: 'POST',
      headers: { accept: 'application/json' },
    });
    const { token } = (await refresh.json()) as { token: string };
    const response = await fetch('/api/object_types?itemsPerPage=200', {
      headers: { accept: 'application/ld+json', authorization: `Bearer ${token}` },
    });
    const payload = (await response.json()) as {
      member?: Array<{ id: string; kind: string; codeImmutable: boolean }>;
    };
    return payload.member ?? [];
  });
  const productType = types.find((t) => t.kind === 'product' && t.codeImmutable);
  if (productType === undefined) throw new Error('Built-in product ObjectType not seeded.');

  const code = `zz_cat_${Date.now().toString(36).toLowerCase()}`;
  const name = `Kategoria E2E ${code}`;

  // Wait for the object_types list so the create handler can resolve the
  // built-in category ObjectType before we submit.
  const objectTypesLoaded = page.waitForResponse(
    (r) => r.url().includes('/api/object_types') && r.request().method() === 'GET',
    { timeout: 30_000 },
  );
  await page.goto(`/modeling/categories/new?targetObjectTypeId=${productType.id}`);
  await objectTypesLoaded;
  // Let Refine commit the object_types list to state so the create
  // handler resolves the built-in category ObjectType.
  await page.waitForTimeout(2_500);
  await page.locator('#cat-code').fill(code);
  await page.locator('#cat-name-pl').fill(name);

  const postPromise = page.waitForResponse(
    (r) => r.url().endsWith('/api/categories') && r.request().method() === 'POST',
    { timeout: 15_000 },
  );
  await page.getByRole('button', { name: /utwórz kategorię|create category/i }).click();
  const post = await postPromise;
  expect(post.status()).toBe(201);
  const createdCategory = (await post.json()) as { id: string };
  const sent = post.request().postDataJSON() as { attributes?: { name?: string } };
  expect(sent.attributes?.name).toBe(name);

  // Back on the tree — the new node renders by its name, not its code.
  await expect(page).toHaveURL(/\/modeling\/categories\?/, { timeout: 15_000 });
  await expect(page.getByText(name).first()).toBeVisible({ timeout: 15_000 });

  // The parent picker uses the readable name as its primary label and keeps
  // the technical path as secondary context.
  await page.goto(`/modeling/categories/new?targetObjectTypeId=${productType.id}`);
  await expect(page.locator('#cat-parent')).toContainText(`${name} — ${code}`, {
    timeout: 15_000,
  });

  // Return to the active split-view and delete the leaf through its Danger
  // zone. This is the operator flow missing before #2942.
  await page.goto(
    `/modeling/categories?targetObjectTypeId=${productType.id}&targetType=product&selected=${createdCategory.id}`,
  );
  await page.getByRole('button', { name: /usuń kategorię|delete category/i }).click();
  const deletePromise = page.waitForResponse(
    (r) =>
      r.url().endsWith(`/api/categories/${createdCategory.id}`) &&
      r.request().method() === 'DELETE',
    { timeout: 15_000 },
  );
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /usuń kategorię|delete category/i })
    .click();
  expect((await deletePromise).status()).toBe(204);
  await expect(page).not.toHaveURL(/selected=/);
  await expect(page.getByText(name)).toHaveCount(0);
});
