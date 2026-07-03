import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-07 (#2037, ADR-0025) — cross-field rules builder on the ObjectType
 * detail page + enforcement on the product write path.
 *
 * Single test, one login. Seeds two numeric attributes on the Product
 * ObjectType via the browser-side API, builds a `compare` rule through
 * the new card, saves, verifies enforcement with a violating API write
 * (422), then cleans everything up.
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
          accept: 'application/json',
          ...(args.body !== undefined
            ? {
                'content-type':
                  args.method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json',
              }
            : {}),
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

test('DP-07 — operator builds a compare rule and the write path enforces it', async ({ page }) => {
  await loginAsAdmin(page);

  // Resolve the Product ObjectType.
  const objectTypes = await browserApi(page, 'GET', '/api/object_types?itemsPerPage=50');
  const productOt = (objectTypes.body as Array<{ id: string; kind: string }>).find(
    (ot) => ot.kind === 'product',
  );
  expect(productOt).toBeTruthy();
  const otId = productOt?.id ?? '';

  const suffix = Date.now().toString(36);
  const netCode = `dp07e2e_net_${suffix}`;
  const grossCode = `dp07e2e_gross_${suffix}`;
  const attrIds: string[] = [];

  for (const code of [netCode, grossCode]) {
    const created = await browserApi(page, 'POST', '/api/attributes', {
      code,
      type: 'number',
      label: { pl: code },
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    const attrId = (created.body as { id: string }).id;
    attrIds.push(attrId);
    const attached = await browserApi(
      page,
      'POST',
      `/api/object_types/${otId}/attributes/${attrId}`,
    );
    expect(attached.status).toBe(204);
  }

  try {
    // Build the rule through the card.
    await page.goto(`/modeling/object-types/${otId}`);
    const card = page
      .getByText(/reguły walidacji \(między polami\)|validation rules/i)
      .first()
      .locator('..')
      .locator('..');
    await expect(card).toBeVisible();

    await page.getByRole('button', { name: /porównanie pól/i }).click();
    const selects = page.getByRole('combobox', { name: /atrybut/i });
    await selects.nth(0).selectOption(netCode);
    await selects.nth(1).selectOption(grossCode);
    await page.getByRole('button', { name: /^(zapisz|save)$/i }).click();

    // Persisted server-side.
    await expect
      .poll(async () => {
        const fetched = await browserApi(page, 'GET', `/api/object_types/${otId}`);
        return JSON.stringify(
          (fetched.body as { validationRules?: unknown[] }).validationRules ?? [],
        );
      })
      .toContain(netCode);

    // Enforcement: violating write on a real product → 422.
    const products = await browserApi(page, 'GET', '/api/products?itemsPerPage=1');
    const productId = (products.body as Array<{ id: string }>)[0]?.id ?? '';
    expect(productId).not.toBe('');

    const violating = await browserApi(page, 'PATCH', `/api/objects/${productId}`, {
      attributes: { [netCode]: 50, [grossCode]: 10 },
    });
    expect(violating.status).toBe(422);
    expect(String((violating.body as { detail?: string }).detail)).toContain(netCode);

    const valid = await browserApi(page, 'PATCH', `/api/objects/${productId}`, {
      attributes: { [netCode]: 5, [grossCode]: 10 },
    });
    expect(valid.status).toBe(200);

    // Tidy the product values.
    await browserApi(page, 'PATCH', `/api/objects/${productId}`, {
      attributes: { [netCode]: null, [grossCode]: null },
    });
  } finally {
    await browserApi(page, 'PATCH', `/api/object_types/${otId}`, { validationRules: [] });
    for (const attrId of attrIds) {
      await browserApi(page, 'DELETE', `/api/object_types/${otId}/attributes/${attrId}`);
      await browserApi(page, 'DELETE', `/api/attributes/${attrId}`);
    }
  }
});
