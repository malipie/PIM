import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * #2723 — a failed detach in the Kategorie tab must surface a toast instead of
 * silently swallowing the error (the UI-02 "dead button" pattern).
 *
 * Setup mirrors 1209 (categorizable custom OT + tree-scoped category + object)
 * but drives the API through in-page fetch — the browser resolves
 * `pim.localhost` everywhere, while Node's `page.request` needs the /etc/hosts
 * mapping only CI has. The DELETE is then mocked to a 403 problem-details
 * response: the toast must show the backend `detail` and the assignment stays.
 */

interface ApiResult<T> {
  status: number;
  body: T;
}

async function api<T>(
  page: Page,
  method: string,
  url: string,
  token: string | null,
  body?: unknown,
  contentType = 'application/json',
): Promise<ApiResult<T>> {
  return page.evaluate(
    async (input) => {
      const headers: Record<string, string> = {};
      if (input.token !== null) headers.Authorization = `Bearer ${input.token}`;
      if (input.body !== undefined) headers['content-type'] = input.contentType;
      const res = await fetch(input.url, {
        method: input.method,
        headers,
        body: input.body === undefined ? undefined : JSON.stringify(input.body),
      });
      let parsed: unknown = null;
      try {
        parsed = await res.json();
      } catch {
        // Empty or non-JSON body — the status is all the caller needs.
      }
      return { status: res.status, body: parsed as never };
    },
    { method, url, token, body, contentType },
  );
}

test('a failed category detach surfaces the backend detail in a toast', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const refresh = await api<{ token: string }>(page, 'POST', '/api/auth/refresh', null);
  expect(refresh.status).toBe(200);
  const token = refresh.body.token;

  const ts = uniqueSku('ERR')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_');
  const slug = `err_${ts}`;

  // Categorizable custom ObjectType + category + object, assignment via API.
  const otResp = await api<{ id: string }>(page, 'POST', '/api/object_types', token, {
    code: slug,
    label: { pl: `Błędy ${ts}`, en: `Errors ${ts}` },
  });
  expect(otResp.status, JSON.stringify(otResp.body)).toBe(201);
  const otId = otResp.body.id;

  const patchResp = await api(
    page,
    'PATCH',
    `/api/object_types/${otId}`,
    token,
    { isCategorizable: true },
    'application/merge-patch+json',
  );
  expect(patchResp.status).toBe(200);

  const typesResp = await api<{
    member?: Array<{ id: string; kind: string }>;
    'hydra:member'?: Array<{ id: string; kind: string }>;
  }>(page, 'GET', '/api/object_types', token);
  const types = typesResp.body.member ?? typesResp.body['hydra:member'] ?? [];
  const categoryOtId = types.find((t) => t.kind === 'category')?.id;
  if (categoryOtId === undefined) throw new Error('Built-in category ObjectType not found.');

  const catResp = await api<{ id: string }>(
    page,
    'POST',
    '/api/categories',
    token,
    {
      code: `ERRCAT-${ts}`,
      objectTypeId: categoryOtId,
      categoryTargetObjectTypeId: otId,
      attributes: { name: `E2E Errors ${ts}` },
    },
    'application/ld+json',
  );
  expect(catResp.status, JSON.stringify(catResp.body)).toBe(201);
  const catId = catResp.body.id;

  const objResp = await api<{ id: string }>(
    page,
    'POST',
    '/api/objects',
    token,
    { code: `ERR-OBJ-${ts}`, objectTypeId: otId, attributes: {} },
    'application/ld+json',
  );
  expect(objResp.status, JSON.stringify(objResp.body)).toBe(201);
  const objId = objResp.body.id;

  const assignResp = await api(page, 'PUT', `/api/objects/${objId}/categories`, token, {
    primaryCategoryId: catId,
    categoryIds: [catId],
  });
  expect(assignResp.status, JSON.stringify(assignResp.body)).toBe(200);

  // Mock the detach to fail with an RFC 7807 detail.
  const detail = 'Brak uprawnienia do odpinania kategorii (E2E).';
  await page.route(`**/api/objects/${objId}/categories/${catId}`, (route) => {
    if (route.request().method() !== 'DELETE') return route.continue();
    return route.fulfill({
      status: 403,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'about:blank', title: 'Forbidden', status: 403, detail }),
    });
  });

  await page.goto(`/objects/${slug}/${objId}`);
  await page.getByRole('tab', { name: /kategorie/i }).click();
  await expect(page.getByText(`ERRCAT-${ts}`).first()).toBeVisible();

  await page
    .getByRole('button', { name: /odepnij kategorię/i })
    .first()
    .click();

  // Two surfaces render category chips: CategoriesTab (detaches straight away)
  // and the sidebar CategorySelectorCard (guards the detach behind the PCAT
  // change-warning dialog). Which one the page mounts depends on the object
  // kind, so confirm the dialog only when it actually appears instead of
  // assuming one of the two layouts.
  const warning = page.getByRole('dialog', { name: /zmiana kategorii/i });
  if (await warning.isVisible().catch(() => false)) {
    await warning.getByRole('button', { name: /potwierdź zmianę/i }).click();
  }

  // The backend detail lands in an error toast and the chip stays.
  await expect(page.getByRole('alert').filter({ hasText: detail })).toBeVisible();
  await expect(page.getByText(`ERRCAT-${ts}`).first()).toBeVisible();
});
