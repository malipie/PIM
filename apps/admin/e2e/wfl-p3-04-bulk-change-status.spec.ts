import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * WFL-P3-04 (#2426) / wiring #2493 — the bulk "Zmień status" action lives
 * in the UniversalListPage bulk bar (the WFL-P3-04 button originally
 * shipped in the never-mounted BulkActionsToolbar). Selecting product
 * rows and applying `submit_for_review` moves them through the state
 * machine (`POST /api/products/bulk-actions/change_status`, per-row can()),
 * and the objects then surface in the review queue.
 */
test('bulk change status submits selected products for review', async ({ page }) => {
  const loginResponse = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(loginResponse.status()).toBe(200);
  const { token } = (await loginResponse.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };

  const typesResponse = await page.request.get('/api/object_types?itemsPerPage=200', {
    headers: { ...bearer, accept: 'application/ld+json' },
  });
  const types = (await typesResponse.json()) as {
    'hydra:member'?: { id: string; kind: string }[];
    member?: { id: string; kind: string }[];
  };
  const productType = (types['hydra:member'] ?? types.member ?? []).find(
    (candidate) => candidate.kind === 'product',
  );
  if (productType === undefined) throw new Error('No product ObjectType seeded.');

  // Two fresh drafts so the selection is deterministic.
  const skus = [uniqueSku('WFL-BULK'), uniqueSku('WFL-BULK')];
  for (const code of skus) {
    const created = await page.request.post('/api/products', {
      data: { code, objectTypeId: productType.id, attributes: {} },
      headers: { ...bearer, 'content-type': 'application/ld+json' },
    });
    expect(created.status()).toBe(201);
  }

  await loginAsAdmin(page);
  await page.goto('/products');

  // Find and select the two drafts we just created (search narrows the grid).
  const search = page.getByRole('searchbox', { name: /szukaj produktów|search products/i });
  await search.fill(skus[0]);
  const firstRow = page.getByTestId(`products-grid-row-${skus[0]}`);
  await expect(firstRow).toBeVisible({ timeout: 30_000 });
  await firstRow.getByRole('checkbox').first().check();

  await search.fill(skus[1]);
  const secondRow = page.getByTestId(`products-grid-row-${skus[1]}`);
  await expect(secondRow).toBeVisible({ timeout: 30_000 });
  await secondRow.getByRole('checkbox').first().check();

  // Open the bulk dialog and apply submit_for_review.
  const changeStatus = page.getByTestId('bulk-change-status');
  await expect(changeStatus).toBeVisible();
  await changeStatus.click();

  await expect(page.getByTestId('bulk-status-transition')).toBeVisible();
  await page.getByTestId('bulk-status-transition').selectOption('submit_for_review');
  await page.getByTestId('bulk-status-confirm').click();

  // Both objects are now in review — the review queue lists them.
  await page.goto('/workflow');
  await expect(page.getByTestId('review-queue-page')).toBeVisible();
  for (const code of skus) {
    await expect(page.getByTestId('review-queue-row').filter({ hasText: code })).toBeVisible();
  }
});
