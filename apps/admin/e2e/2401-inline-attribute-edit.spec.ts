import { expect, test } from '@playwright/test';

import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * GRID-P6-02 (#2401) — typed inline editors for attribute columns in the
 * Excel view. Seeds a custom ObjectType with an editable text attribute,
 * reveals it as a column, edits the cell in Excel view, and asserts the
 * PATCH persists (value survives reload).
 */

test('edits an attribute value inline in the Excel view and persists it', async ({ page }) => {
  test.setTimeout(120_000);
  await loginAsAdmin(page);

  const refresh = await page.request.post('/api/auth/refresh');
  expect(refresh.status()).toBe(200);
  const token = ((await refresh.json()) as { token: string }).token;
  const bearer = { Authorization: `Bearer ${token}` };
  const json = { ...bearer, accept: 'application/ld+json', 'content-type': 'application/json' };

  const stamp = uniqueSku('edit')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_');
  const otCode = `edit_ot_${stamp}`;
  const groupCode = `edit_grp_${stamp}`;
  const attrCode = `edit_note_${stamp}`;
  const attrLabel = `Notatka ${stamp}`;

  const ot = await page.request.post('/api/object_types', {
    data: { code: otCode, label: { pl: `Edytowalne ${stamp}`, en: `Editable ${stamp}` } },
    headers: json,
  });
  expect(ot.status(), await ot.text()).toBe(201);
  const objectTypeId = ((await ot.json()) as { id: string }).id;

  const group = await page.request.post('/api/attribute_groups', {
    data: { code: groupCode, label: { pl: 'Podstawowe', en: 'Basics' } },
    headers: json,
  });
  expect(group.status()).toBe(201);
  const groupId = ((await group.json()) as { id: string }).id;

  const attr = await page.request.post('/api/attributes', {
    data: {
      code: attrCode,
      type: 'text',
      label: { pl: attrLabel, en: attrLabel },
      required: false,
    },
    headers: { ...bearer, accept: 'application/ld+json', 'content-type': 'application/ld+json' },
  });
  expect(attr.status(), await attr.text()).toBe(201);
  const attributeId = ((await attr.json()) as { id: string }).id;

  await page.request.post(`/api/attribute_groups/${groupId}/attributes/bulk-attach`, {
    data: { attributeCodes: [attrCode] },
    headers: { ...bearer, accept: 'application/json', 'content-type': 'application/json' },
  });
  await page.request.post(`/api/object_types/${objectTypeId}/groups/${groupId}`, {
    headers: bearer,
  });
  await page.request.post(`/api/object_types/${objectTypeId}/attributes/bulk-attach`, {
    data: { attributeIds: [attributeId] },
    headers: { ...bearer, accept: 'application/json', 'content-type': 'application/json' },
  });
  const listConfig = await page.request.patch(
    `/api/object_types/${objectTypeId}/attributes/${attributeId}/list-config`,
    {
      data: { show_in_list: true, list_position: 10 },
      headers: { ...bearer, accept: 'application/json', 'content-type': 'application/json' },
    },
  );
  expect(listConfig.status(), await listConfig.text()).toBe(200);

  const objectCode = uniqueSku('EDIT');
  const obj = await page.request.post('/api/objects', {
    data: { code: objectCode, objectTypeId, attributes: {} },
    headers: { ...bearer, accept: 'application/ld+json', 'content-type': 'application/ld+json' },
  });
  expect(obj.status(), await obj.text()).toBe(201);

  // Excel view, edit the attribute cell.
  await page.goto(`/objects/${otCode}`);
  await page.getByRole('tab', { name: 'Excel' }).click();
  const header = page.getByRole('columnheader', { name: attrLabel });
  await expect(header).toBeVisible();

  const newValue = `Bawelna-${stamp}`;
  const patchResponse = page.waitForResponse(
    (r) =>
      /\/api\/objects\/[^/]+$/.test(r.url()) &&
      r.request().method() === 'PATCH' &&
      r.request().postData()?.includes(attrCode) === true,
  );
  // The row's attribute cell starts empty (—); click it, type, commit.
  const cell = page.getByRole('cell').filter({ hasText: '—' }).first();
  await cell.click();
  await page.keyboard.type(newValue);
  await page.keyboard.press('Enter');
  const patch = await patchResponse;
  expect(patch.status()).toBe(200);

  // Value persists after reload (server truth).
  await page.reload();
  await page.getByRole('tab', { name: 'Excel' }).click();
  await expect(page.getByRole('cell', { name: newValue })).toBeVisible();
});
