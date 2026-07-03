import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CHC-08 (#1291) — channel category mapping split-view: map a master category
 * to a channel navigation node from the "Kategorie kanału" tab, which drives
 * CHC-07 auto-assignment.
 *
 * `fixme` in CI for the same auth rate-limiter reason as the other product/
 * channel detail specs (#1209) — runs locally against `pnpm stack:up`.
 */

test('map a master category to a channel node from the channel mapping tab', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const refreshResponse = await page.request.post('/api/auth/refresh');
  expect(refreshResponse.status()).toBe(200);
  const accessToken = ((await refreshResponse.json()) as { token: string }).token;
  const bearer = { Authorization: `Bearer ${accessToken}` };
  const json = { ...bearer, 'content-type': 'application/json', accept: 'application/json' };

  // 1. A channel.
  const channelsBody = (await (
    await page.request.get('/api/channels', { headers: { ...bearer, accept: 'application/json' } })
  ).json()) as { member?: Array<{ id: string }> } | Array<{ id: string }>;
  const channels = Array.isArray(channelsBody) ? channelsBody : (channelsBody.member ?? []);
  expect(channels.length).toBeGreaterThan(0);
  const channelId = channels[0].id;

  // Clean slate: drop any leftover navigation tree + mappings so options and
  // assertions stay deterministic across re-runs (failed runs skip cleanup).
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
  await page.request.delete(`/api/channels/${channelId}/node-mappings`, { headers: bearer });

  // 2. A fresh navigation tree: root + one node, with distinct tokens so the
  // node option ("MAP08") never collides with the root ("E2E08").
  const rootResp = await page.request.post(`/api/channels/${channelId}/navigation-tree`, {
    headers: json,
    data: { label: { pl: 'Korzeń E2E08', en: 'Root E2E08' } },
  });
  expect(rootResp.status()).toBe(201);
  const rootId = ((await rootResp.json()) as { id: string }).id;

  await page.request.post(`/api/channels/${channelId}/navigation-tree/nodes`, {
    headers: json,
    data: { parentId: rootId, code: 'e2e_map08', label: { pl: 'Węzeł MAP08', en: 'Node MAP08' } },
  });

  // 3. Open the channel "Kategorie kanału" tab.
  await page.goto(`/modeling/channels/${channelId}`);
  // The admin renders in EN or PL depending on the session locale — match both.
  await page.getByRole('button', { name: /Channel categories|Kategorie kanału/i }).click();

  await expect(page.getByTestId('chc-master-list')).toBeVisible();
  // Master categories show their real name (from attributesIndexed), not "—".
  await expect(
    page
      .getByTestId('chc-master-list')
      .getByText(/Obuwie|Odzież|Bieganie|Outdoor/)
      .first(),
  ).toBeVisible();
  await expect(page.getByTestId('chc-channel-tree')).toBeVisible();
  await expect(page.getByText(/MAP08/i).first()).toBeVisible();

  // 4. Map the first master category to the node via the dialog.
  await page.locator('[data-testid^="chc-map-"]').first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();

  await dialog.getByRole('button', { name: /select channel nodes|wybierz węzły kanału/i }).click();
  await dialog.getByRole('button', { name: /MAP08/i }).click();
  // Dismiss the open MultiSelect dropdown (z-50 overlay) so it stops covering
  // the footer's Save button — click the dialog heading (outside the picker).
  await dialog.getByRole('heading').first().click();

  const putResponse = page.waitForResponse(
    (r) => /\/node-mappings\//.test(r.url()) && r.request().method() === 'PUT',
  );
  await dialog.getByRole('button', { name: /^(Save|Zapisz)$/ }).click();
  expect((await putResponse).status()).toBe(200);

  // 5. The mapped-count summary surfaces on a master row.
  await expect(page.getByText(/mapped to|zmapowano do/i).first()).toBeVisible();

  // cleanup — clear the mappings and the navigation tree we created.
  await page.request.delete(`/api/channels/${channelId}/node-mappings`, { headers: bearer });
  await page.request.delete(`/api/channels/${channelId}/navigation-tree/nodes/${rootId}`, {
    headers: bearer,
  });
});
