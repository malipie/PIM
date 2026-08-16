import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * CHC-03 (#1286) — "Gdzie trafia na kanałach" section in the product
 * "Kategorie" tab: assign a product to a channel navigation node.
 *
 * `fixme` in CI for the same auth rate-limiter reason as the other product
 * detail UI specs (#1209) — runs locally against `pnpm stack:up`.
 */

test('assign a product to a channel navigation node from the Kategorie tab', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const refreshResponse = await page.request.post('/api/auth/refresh');
  expect(refreshResponse.status()).toBe(200);
  const accessToken = ((await refreshResponse.json()) as { token: string }).token;
  const bearer = { Authorization: `Bearer ${accessToken}` };
  const json = { ...bearer, 'content-type': 'application/json', accept: 'application/json' };

  // 1. A channel + a navigation tree (root + one node).
  const channelsBody = (await (
    await page.request.get('/api/channels', { headers: { ...bearer, accept: 'application/json' } })
  ).json()) as { member?: Array<{ id: string }> } | Array<{ id: string }>;
  const channels = Array.isArray(channelsBody) ? channelsBody : (channelsBody.member ?? []);
  expect(channels.length).toBeGreaterThan(0);
  const channelId = channels[0].id;

  const rootResp = await page.request.post(`/api/channels/${channelId}/navigation-tree`, {
    headers: json,
    data: { label: { pl: 'Korzeń E2E' } },
  });
  // 201 on first run; 409 if a previous local run left a tree — reuse it then.
  expect([201, 409]).toContain(rootResp.status());
  const treeBody = (await (
    await page.request.get(`/api/channels/${channelId}/navigation-tree`, {
      headers: { ...bearer, accept: 'application/json' },
    })
  ).json()) as Array<{ id: string; parentId: string | null }>;
  const rootId = treeBody.find((n) => n.parentId === null)?.id;
  if (rootId === undefined) throw new Error('Navigation root missing after creation.');

  await page.request.post(`/api/channels/${channelId}/navigation-tree/nodes`, {
    headers: json,
    data: { parentId: rootId, code: 'e2e_telewizory', label: { pl: 'E2E Telewizory' } },
  });

  // 2. A product to place.
  const productsBody = (await (
    await page.request.get('/api/products?itemsPerPage=1', {
      headers: { ...bearer, accept: 'application/json' },
    })
  ).json()) as { member?: Array<{ id: string }> } | Array<{ id: string }>;
  const products = Array.isArray(productsBody) ? productsBody : (productsBody.member ?? []);
  expect(products.length).toBeGreaterThan(0);
  const productId = products[0].id;

  // 3. Open the product "Kategorie" tab.
  await page.goto(`/products/${productId}`);
  await page.getByRole('tab', { name: /kategorie/i }).click();

  const section = page.getByText(/Gdzie trafia na kanałach/i);
  await expect(section).toBeVisible();

  // 4. Assign the node via the picker dialog.
  await page
    .getByRole('button', { name: /^(Przypisz|Nadpisz)$/ })
    .first()
    .click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: /E2E Telewizory/i }).click();

  const putResponse = page.waitForResponse(
    (r) => r.url().includes(`/channel-placements/${channelId}`) && r.request().method() === 'PUT',
  );
  await dialog.getByRole('button', { name: /^Przypisz$/ }).click();
  expect((await putResponse).status()).toBe(200);

  // 5. The placement breadcrumb + manual marker surface in the row.
  // #2881 — wait for the picker to close first. The dialog carries a button
  // with the same node name, so asserting while it is still on screen is a
  // strict-mode violation ("resolved to 2 elements") rather than a failure:
  // the placement had already succeeded, the dialog just had not gone yet.
  // Same class as the counted-nav-item assertion from #2845 — the assertion
  // was measuring render timing, not the placement.
  await expect(dialog).toBeHidden();
  await expect(page.getByText(/E2E Telewizory/i)).toBeVisible();
  await expect(page.getByText(/\(ręcznie\)/i)).toBeVisible();

  // cleanup — remove the navigation tree we created (cascade).
  await page.request.delete(`/api/channels/${channelId}/navigation-tree/nodes/${rootId}`, {
    headers: bearer,
  });
});
