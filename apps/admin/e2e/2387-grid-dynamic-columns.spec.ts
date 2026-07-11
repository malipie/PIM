import { expect, test } from '@playwright/test';

import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * GRID-P1-03/04 (#2387 / #2388) — both list views build their data
 * columns from the resolved column model (list-schema + overrides)
 * instead of hardcoded sets:
 *
 *  1. A fresh custom ObjectType with a `show_in_list=true` text
 *     attribute renders that attribute as a column WITH the object's
 *     value — in the grid view and, after toggling, in the Excel view
 *     (read-only until GRID-P6-02).
 *  2. /products still renders the model-driven default set (visual
 *     parity: SKU + name present, rows intact) — regression guard.
 */

test('custom ObjectType renders its own attribute column with values in both views', async ({
  page,
}) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const refreshResponse = await page.request.post('/api/auth/refresh');
  expect(refreshResponse.status()).toBe(200);
  const accessToken = ((await refreshResponse.json()) as { token: string }).token;
  const bearer = { Authorization: `Bearer ${accessToken}` };
  const json = { ...bearer, accept: 'application/ld+json', 'content-type': 'application/json' };

  const stamp = uniqueSku('grid')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_');
  const otCode = `grid_ot_${stamp}`;
  const groupCode = `grid_grp_${stamp}`;
  const attrCode = `grid_material_${stamp}`;
  const attrLabelPl = `Materiał E2E ${stamp}`;
  const cellValue = `Bawełna-${stamp}`;

  // 1. Custom ObjectType (no variants/multimedia — capability AC).
  const otResp = await page.request.post('/api/object_types', {
    data: { code: otCode, label: { pl: `Tkaniny ${stamp}`, en: `Fabrics ${stamp}` } },
    headers: json,
  });
  expect(otResp.status(), await otResp.text()).toBe(201);
  const objectTypeId = ((await otResp.json()) as { id: string }).id;

  // 2. Group + text attribute attached to the ObjectType.
  const groupResp = await page.request.post('/api/attribute_groups', {
    data: { code: groupCode, label: { pl: 'Podstawowe', en: 'Basics' } },
    headers: json,
  });
  expect(groupResp.status(), await groupResp.text()).toBe(201);
  const groupId = ((await groupResp.json()) as { id: string }).id;

  const attrResp = await page.request.post('/api/attributes', {
    data: {
      code: attrCode,
      type: 'text',
      label: { pl: attrLabelPl, en: `Material E2E ${stamp}` },
      required: false,
    },
    headers: { ...bearer, accept: 'application/ld+json', 'content-type': 'application/ld+json' },
  });
  expect(attrResp.status(), await attrResp.text()).toBe(201);
  const attributeId = ((await attrResp.json()) as { id: string }).id;

  const attachAttr = await page.request.post(
    `/api/attribute_groups/${groupId}/attributes/bulk-attach`,
    {
      data: { attributeCodes: [attrCode] },
      headers: { ...bearer, accept: 'application/json', 'content-type': 'application/json' },
    },
  );
  expect(attachAttr.status(), await attachAttr.text()).toBe(200);

  const attachGroup = await page.request.post(
    `/api/object_types/${objectTypeId}/groups/${groupId}`,
    { headers: bearer },
  );
  expect(attachGroup.status()).toBe(204);

  // 3. Flag the attribute as a list column (ULV-10 junction config).
  const listConfig = await page.request.patch(
    `/api/object_types/${objectTypeId}/attributes/${attributeId}/list-config`,
    {
      data: { show_in_list: true, list_position: 10 },
      headers: { ...bearer, accept: 'application/json', 'content-type': 'application/json' },
    },
  );
  expect(listConfig.status(), await listConfig.text()).toBe(200);

  // 4. One object + value via the proven merge-patch write path (same
  //    shape the Excel commit uses).
  const objectCode = uniqueSku('GRID');
  const objResp = await page.request.post('/api/objects', {
    headers: { ...bearer, accept: 'application/ld+json', 'content-type': 'application/ld+json' },
    data: { code: objectCode, objectTypeId, attributes: {} },
  });
  expect(objResp.status(), await objResp.text()).toBe(201);
  const objectId = ((await objResp.json()) as { id: string }).id;

  const valuePatch = await page.request.patch(`/api/objects/${objectId}`, {
    headers: { ...bearer, 'content-type': 'application/merge-patch+json' },
    data: { attributes: { [attrCode]: cellValue } },
  });
  expect(valuePatch.status(), await valuePatch.text()).toBe(200);

  // 5. Grid view: the attribute column renders with the object's value.
  await page.goto(`/objects/${otCode}`);
  await expect(page.getByTestId(`grid-header-${attrCode}`)).toHaveText(attrLabelPl);
  const row = page.getByTestId(`products-grid-row-${objectCode}`);
  await expect(row).toBeVisible();
  await expect(row.getByTestId(`grid-cell-${attrCode}`)).toHaveText(cellValue);

  // 6. Excel view keeps the same column (read-only) with the same value.
  await page.getByRole('tab', { name: 'Excel' }).click();
  await expect(page.getByRole('columnheader', { name: attrLabelPl })).toBeVisible();
  await expect(page.getByRole('cell', { name: cellValue })).toBeVisible();
});

test('products list renders model-driven default columns without regression', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/products');

  // Model-driven headers: identifier + name are always present; the
  // legacy defaults (categories/completeness) resolve from schema/view
  // seeds. `grid-header-*` testids only exist on the dynamic path, so
  // this also guards against a silent fallback to hardcoded columns.
  await expect(page.getByTestId('grid-header-code')).toBeVisible();
  await expect(
    page.getByTestId('grid-header-name').or(page.getByTestId('grid-header-__name')),
  ).toBeVisible();
  await expect(page.getByTestId('grid-header-completeness')).toBeVisible();

  // Rows still render (no empty-model regression).
  await expect(page.getByTestId('products-grid')).toBeVisible();
  const anyRow = page.locator('[data-testid^="products-grid-row-"]').first();
  await expect(anyRow).toBeVisible();
});
