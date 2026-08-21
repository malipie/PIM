import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2943 — the create form for a custom ObjectType.
 *
 * Two reports, one flow. The identifier had to be invented by hand for every
 * row, because a custom module has no SKU to copy; and the name the operator
 * typed appeared not to save at all — it was stored, but nothing rendered it,
 * because a fresh type had no label attribute and every surface fell back to
 * `objects.code`.
 */
test('a custom ObjectType prefills its identifier and keeps the typed name', async ({ page }) => {
  test.setTimeout(180_000);

  await loginAsAdmin(page);

  const run = `e2e2943${Date.now().toString(36)}`;
  const created = await page.evaluate(async (code) => {
    const refresh = await fetch('/api/auth/refresh', {
      method: 'POST',
      headers: { accept: 'application/json' },
    });
    const { token } = (await refresh.json()) as { token: string };
    const response = await fetch('/api/object_types', {
      method: 'POST',
      headers: {
        accept: 'application/ld+json',
        authorization: `Bearer ${token}`,
        'content-type': 'application/ld+json',
      },
      body: JSON.stringify({
        code,
        kind: 'custom',
        label: { pl: 'Twórcy E2E', en: 'Creators E2E' },
      }),
    });
    const body = (await response.json()) as { id?: string };
    return { status: response.status, id: body.id ?? null };
  }, run);

  expect(created.status).toBe(201);
  const objectTypeId = created.id;
  if (objectTypeId === null) throw new Error('custom ObjectType was not created');

  try {
    await page.goto(`/objects/${run}/new`);

    // The identifier arrives filled in — the operator no longer invents one.
    const idField = page.getByPlaceholder(/^ID$/);
    await expect(idField).toHaveValue(`${run}_000001`, { timeout: 20_000 });

    const name = 'Stanisław Lem';
    await page.getByPlaceholder(/^Nazwa$|^Name$/).fill(name);

    const postPromise = page.waitForResponse(
      (r) => r.url().endsWith('/api/objects') && r.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.getByRole('button', { name: /^utwórz$|^create$/i }).click();
    const post = await postPromise;
    expect(post.status()).toBe(201);

    // The name the operator typed rides the create payload...
    const sent = post.request().postDataJSON() as { attributes?: { name?: string } };
    expect(sent.attributes?.name).toBe(name);

    // ...and is what the object list shows, rather than the technical code.
    await page.goto(`/objects/${run}`);
    await expect(page.getByText(name).first()).toBeVisible({ timeout: 20_000 });
  } finally {
    await page.evaluate(async (typeId) => {
      const refresh = await fetch('/api/auth/refresh', {
        method: 'POST',
        headers: { accept: 'application/json' },
      });
      const { token } = (await refresh.json()) as { token: string };
      const headers = { authorization: `Bearer ${token}`, accept: 'application/ld+json' };
      const list = (await (
        await fetch(`/api/objects?itemsPerPage=200&objectType=${typeId}`, { headers })
      ).json()) as { member?: Array<{ id: string }> };
      for (const row of list.member ?? []) {
        await fetch(`/api/objects/${row.id}`, { method: 'DELETE', headers });
      }
      await fetch(`/api/object_types/${typeId}`, { method: 'DELETE', headers });
    }, objectTypeId);
  }
});
