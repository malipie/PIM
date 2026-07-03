import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-08 (#2039) — conditional visibility end to end.
 *
 * Single test, one login. Adds a driver + dependent text attribute to an
 * existing stacked group of the demo product's form schema, sets a
 * `visible_when` rule on the dependent through the group-detail dialog,
 * then proves the product form hides/reveals the dependent as the driver
 * value changes — and that the hidden value is NOT cleared. Cleans up.
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
                  args.method === 'PATCH' ? 'application/json' : 'application/ld+json',
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

test('DP-08 — visible_when hides and reveals a field without losing its value', async ({
  page,
}) => {
  await loginAsAdmin(page);

  const suffix = Date.now().toString(36);
  const driverCode = `dp08_driver_${suffix}`;
  const depCode = `dp08_dep_${suffix}`;

  // A stacked (non-system) group already on the demo product's form.
  const products = await browserApi(page, 'GET', '/api/products?itemsPerPage=1');
  const productId = (products.body as Array<{ id: string }>)[0]?.id ?? '';
  expect(productId).not.toBe('');

  const schema = await browserApi(page, 'GET', `/api/objects/${productId}/form-schema`);
  const groups = (
    schema.body as {
      effectiveGroups: Array<{
        id: string;
        code: string;
        display_mode: string;
        is_system_group: boolean;
      }>;
    }
  ).effectiveGroups;
  const group = groups.find((g) => g.display_mode === 'stacked' && !g.is_system_group);
  expect(group, 'demo product needs a stacked non-system group').toBeTruthy();
  const groupId = group?.id ?? '';

  const attrIds: string[] = [];
  for (const code of [driverCode, depCode]) {
    const created = await browserApi(page, 'POST', '/api/attributes', {
      code,
      type: 'text',
      label: { pl: code },
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    attrIds.push((created.body as { id: string }).id);
  }
  const attach = await browserApi(
    page,
    'POST',
    `/api/attribute_groups/${groupId}/attributes/bulk-attach`,
    {
      attributeCodes: [driverCode, depCode],
    },
  );
  expect(attach.status, JSON.stringify(attach.body)).toBe(200);

  try {
    // Set the rule through the group-detail dialog. The member rows are the
    // grid-cols containers — scope to the ONE holding depCode (an unscoped
    // div.filter matches every ancestor and .first() lands on the page root,
    // whose first rule button belongs to a different row).
    await page.goto(`/modeling/attribute-groups/${groupId}`);
    const depRow = page
      .locator('[class*="grid-cols-"]')
      .filter({ has: page.getByText(depCode, { exact: true }) })
      .last();
    await depRow.getByRole('button', { name: /reguła widoczności/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByLabel(/atrybut/i).selectOption(driverCode);
    await dialog.getByLabel(/wartość|value/i).fill('bulb');
    await dialog.getByRole('button', { name: /^(zapisz|save)$/i }).click();
    await expect(page.getByText(`when ${driverCode}=bulb`).first()).toBeVisible();

    // Seed a value on the dependent while it would be hidden — proves the
    // value survives hiding (set via API, like legacy data).
    await page.evaluate(
      async (args: { productId: string; depCode: string }) => {
        const refresh = await fetch('/api/auth/refresh', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { accept: 'application/json' },
        });
        const { token } = (await refresh.json()) as { token: string };
        await fetch(`/api/objects/${args.productId}`, {
          method: 'PATCH',
          headers: {
            authorization: `Bearer ${token}`,
            'content-type': 'application/merge-patch+json',
          },
          body: JSON.stringify({ attributes: { [args.depCode]: 'przetrwam' } }),
        });
      },
      { productId, depCode },
    );

    // Product form: driver empty → dependent hidden.
    await page.goto(`/products/${productId}`);
    const driverField = page.getByText(driverCode, { exact: true }).first();
    await expect(driverField).toBeVisible();
    await expect(page.getByText(depCode, { exact: true })).toHaveCount(0);

    // Fill the driver with the matching value → dependent appears, and its
    // previously-set value survived the hidden phase.
    const driverInput = page
      .locator(`[data-attr-code="${driverCode}"] input, [id*="${driverCode}"]`)
      .first();
    const row = page
      .locator('div')
      .filter({ has: page.getByText(driverCode, { exact: true }) })
      .locator('input[type="text"]')
      .first();
    const input = (await driverInput.count()) > 0 ? driverInput : row;
    await input.fill('bulb');

    await expect(page.getByText(depCode, { exact: true }).first()).toBeVisible();
    const depInput = page
      .locator('div')
      .filter({ has: page.getByText(depCode, { exact: true }) })
      .locator('input[type="text"]')
      .last();
    await expect(depInput).toHaveValue('przetrwam');

    // Flip the driver away → dependent hides again (value NOT cleared —
    // asserted server-side below).
    await input.fill('led');
    await expect(page.getByText(depCode, { exact: true })).toHaveCount(0);

    const check = await browserApi(page, 'GET', `/api/objects/${productId}`);
    const indexed = (check.body as { attributesIndexed?: Record<string, { value?: unknown }> })
      .attributesIndexed;
    expect(indexed?.[depCode]?.value).toBe('przetrwam');
  } finally {
    await page.evaluate(
      async (args: { productId: string; codes: string[] }) => {
        const refresh = await fetch('/api/auth/refresh', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { accept: 'application/json' },
        });
        const { token } = (await refresh.json()) as { token: string };
        await fetch(`/api/objects/${args.productId}`, {
          method: 'PATCH',
          headers: {
            authorization: `Bearer ${token}`,
            'content-type': 'application/merge-patch+json',
          },
          body: JSON.stringify({
            attributes: Object.fromEntries(args.codes.map((c) => [c, null])),
          }),
        });
      },
      { productId, codes: [driverCode, depCode] },
    );
    for (const attrId of attrIds) {
      await browserApi(page, 'DELETE', `/api/attribute_groups/${groupId}/attributes/${attrId}`);
      await browserApi(page, 'DELETE', `/api/attributes/${attrId}`);
    }
  }
});
