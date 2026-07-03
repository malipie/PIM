import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CHC-09 (#1302) — manual channel category-tree editor on the "Drzewo kanału"
 * tab: create tree → add sub-nodes → edit (name + external id) → move → delete.
 *
 * `fixme` in CI for the same auth rate-limiter reason as the other channel
 * detail specs (#1209) — runs locally against `pnpm stack:up`.
 *
 * Actions target the root via `.first()` (the root row renders first, so its
 * action buttons are first in DOM order); "move" targets the first non-root.
 */

test('build, edit, move and delete a channel navigation tree from the UI', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const refreshResponse = await page.request.post('/api/auth/refresh');
  expect(refreshResponse.status()).toBe(200);
  const accessToken = ((await refreshResponse.json()) as { token: string }).token;
  const bearer = { Authorization: `Bearer ${accessToken}` };

  // A channel + a clean slate (failed runs skip cleanup).
  const channelsBody = (await (
    await page.request.get('/api/channels', { headers: { ...bearer, accept: 'application/json' } })
  ).json()) as { member?: Array<{ id: string }> } | Array<{ id: string }>;
  const channels = Array.isArray(channelsBody) ? channelsBody : (channelsBody.member ?? []);
  expect(channels.length).toBeGreaterThan(0);
  const channelId = channels[0].id;

  const existing = (await (
    await page.request.get(`/api/channels/${channelId}/navigation-tree`, {
      headers: { ...bearer, accept: 'application/json' },
    })
  ).json()) as Array<{ id: string; parentId: string | null }>;
  for (const root of existing.filter((n) => n.parentId === null)) {
    await page.request.delete(`/api/channels/${channelId}/navigation-tree/nodes/${root.id}`, {
      headers: bearer,
    });
  }

  await page.goto(`/modeling/channels/${channelId}`);
  await page.getByRole('button', { name: /Drzewo kanału|Channel tree/i }).click();

  const saveName = async (name: string, external?: string) => {
    const dialog = page.getByRole('dialog');
    await dialog.locator('#chc-node-name').fill(name);
    if (external !== undefined) await dialog.locator('#chc-node-external').fill(external);
    await dialog.getByRole('button', { name: /^(Save|Zapisz)$/ }).click();
  };

  const addTopLevel = /Dodaj kategorię główną|Add top-level category/i;

  // 1. Create the first top-level category (empty-state button).
  await page.getByRole('button', { name: addTopLevel }).click();
  await saveName('ROOT09');
  const editor = page.getByTestId('chc-tree-editor');
  await expect(editor).toBeVisible();
  await expect(editor.getByText('ROOT09')).toBeVisible();

  // 2. Add a SECOND top-level category (the bug-1 fix: header button stays).
  await page.getByRole('button', { name: addTopLevel }).click();
  await saveName('ROOT09B');
  await expect(editor.getByText('ROOT09')).toBeVisible();
  await expect(editor.getByText('ROOT09B')).toBeVisible();

  // 3. Edit the first root (first edit button) — rename + set external id.
  await editor
    .getByRole('button', { name: /^(Edit|Edytuj)$/ })
    .first()
    .click();
  await saveName('ROOT09X', '999');
  await expect(editor.getByText('ROOT09X')).toBeVisible();
  await expect(editor.getByText('#999')).toBeVisible();

  // 4. Add a sub-node under the first root (its add button is first).
  await editor
    .getByRole('button', { name: /Add sub-node|Dodaj podpozycję/i })
    .first()
    .click();
  await saveName('NODEA09');
  await expect(editor.getByText('NODEA09')).toBeVisible();

  // 5. Move NODEA09 (first non-root → first move button) under ROOT09B.
  await editor
    .getByRole('button', { name: /^(Move|Przenieś)$/ })
    .first()
    .click();
  const moveResponse = page.waitForResponse(
    (r) => /\/nodes\/.+\/move$/.test(r.url()) && r.request().method() === 'PATCH',
  );
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /ROOT09B/i })
    .click();
  expect((await moveResponse).status()).toBe(200);

  // 6. Delete the first root → DELETE 204; the other root (ROOT09B) survives.
  await editor
    .getByRole('button', { name: /^(Delete|Usuń)$/ })
    .first()
    .click();
  const deleteResponse = page.waitForResponse(
    (r) => /\/navigation-tree\/nodes\//.test(r.url()) && r.request().method() === 'DELETE',
  );
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /^(Delete|Usuń)$/ })
    .click();
  expect((await deleteResponse).status()).toBe(204);
  await expect(editor.getByText('ROOT09B')).toBeVisible();
  await expect(editor.getByText('ROOT09X')).toHaveCount(0);

  // cleanup — ensure no leftover tree.
  const after = (await (
    await page.request.get(`/api/channels/${channelId}/navigation-tree`, {
      headers: { ...bearer, accept: 'application/json' },
    })
  ).json()) as Array<{ id: string; parentId: string | null }>;
  for (const root of after.filter((n) => n.parentId === null)) {
    await page.request.delete(`/api/channels/${channelId}/navigation-tree/nodes/${root.id}`, {
      headers: bearer,
    });
  }
});
