import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-01 (#2031) — MoveCategoryDialog over PATCH /api/categories/{id}/move.
 *
 * Single test, one login. Creates two throwaway root categories via the
 * browser-side API (access token minted from the HttpOnly refresh cookie —
 * the app keeps the JWT in module memory only), moves one under the other
 * through the dialog, verifies the ltree path, then deletes both so the
 * demo tree stays untouched. No products involved → the 409 impact gate
 * stays quiet.
 */

async function browserApi(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async (args: { method: string; path: string; body?: unknown }) => {
      const refresh = await fetch('/api/auth/refresh', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { accept: 'application/json' },
      });
      const { token } = (await refresh.json()) as { token: string };
      const res = await fetch(args.path, {
        method: args.method,
        headers: {
          authorization: `Bearer ${token}`,
          accept: 'application/ld+json',
          ...(args.body !== undefined ? { 'content-type': 'application/ld+json' } : {}),
        },
        ...(args.body !== undefined ? { body: JSON.stringify(args.body) } : {}),
      });
      const text = await res.text();
      let parsed: unknown = null;
      try {
        parsed = text === '' ? null : JSON.parse(text);
      } catch {
        parsed = text;
      }
      return { status: res.status, body: parsed };
    },
    { method, path, body },
  );
}

test('DP-01 — operator re-parents a category via the Move dialog', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/modeling/categories');
  await expect(page.getByText(/drzewo kategorii|category tree/i).first()).toBeVisible();

  // The dropdown stamps targetObjectTypeId into the URL on mount.
  await expect(page).toHaveURL(/targetObjectTypeId=[0-9a-f-]{36}/);
  const targetObjectTypeId = new URL(page.url()).searchParams.get('targetObjectTypeId');

  const suffix = Date.now().toString(36);
  const movingCode = `dp01_moving_${suffix}`;
  const parentCode = `dp01_parent_${suffix}`;

  // POST /api/categories requires the built-in Category ObjectType id.
  const objectTypes = await browserApi(page, 'GET', '/api/object_types');
  const categoryOt = (
    (objectTypes.body as { member?: Array<{ id: string; kind: string }> }).member ?? []
  ).find((ot) => ot.kind === 'category');
  expect(categoryOt, 'built-in category ObjectType must exist').toBeTruthy();

  const ids: string[] = [];
  for (const code of [parentCode, movingCode]) {
    const created = await browserApi(page, 'POST', '/api/categories', {
      code,
      objectTypeId: categoryOt?.id,
      categoryTargetObjectTypeId: targetObjectTypeId,
      attributes: { name: code },
    });
    expect(created.status, JSON.stringify(created.body)).toBeLessThan(300);
    ids.push((created.body as { id: string }).id);
  }

  try {
    // Select the moving category in the tree.
    await page.reload();
    const movingRow = page.getByRole('button', { name: new RegExp(movingCode) }).first();
    await expect(movingRow).toBeVisible();
    await movingRow.click();

    // Open the Move dialog from the detail panel and pick the new parent.
    await page.getByRole('button', { name: /^(przenieś|move)$/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: new RegExp(parentCode) }).click();
    await dialog.getByRole('button', { name: /^(przenieś|move)$/i }).click();

    // Toast confirms and the detail panel shows the nested ltree path.
    await expect(page.getByText(/przeniesiona|moved/i).first()).toBeVisible();
    await expect(page.getByText(new RegExp(`${parentCode}\\.${movingCode}`)).first()).toBeVisible();
  } finally {
    // Tidy up: child first (its path nests under the parent), then parent.
    const movingId = ids[1];
    const parentId = ids[0];
    if (movingId) await browserApi(page, 'DELETE', `/api/categories/${movingId}`);
    if (parentId) await browserApi(page, 'DELETE', `/api/categories/${parentId}`);
  }
});
