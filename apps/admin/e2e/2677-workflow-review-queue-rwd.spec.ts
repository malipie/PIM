import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, apiLogin, uniqueSku } from './helpers/auth';

/**
 * #2677 — the review queue was reformatted from a `<table>` (which forced a
 * horizontal scroll on narrow viewports, hiding the approve/reject actions
 * off screen) into an ObjectType-style card list that stacks vertically on
 * mobile. This spec pins the RWD contract: at 390px the page must not scroll
 * horizontally and the approve action must stay reachable + clickable.
 */
test('#2677 — review queue is usable at mobile width (no horizontal overflow, actions reachable)', async ({
  page,
}) => {
  const loginResponse = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(loginResponse.status()).toBe(200);
  const { token } = (await loginResponse.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };

  await apiLogin(page);

  // Seed one submitted object so the queue is non-empty.
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

  const sku = uniqueSku('WFL-RWD');
  const createResponse = await page.request.post('/api/objects', {
    data: {
      code: sku,
      objectTypeId: productType.id,
      attributes: {
        sku,
        name: `RWD ${sku}`,
        description: 'Opis do przeglądu',
        price: { amount: 20, currency: 'PLN' },
      },
    },
    headers: { ...bearer, 'content-type': 'application/ld+json' },
  });
  expect(createResponse.status()).toBe(201);
  const created = (await createResponse.json()) as { id: string };

  const submit = await page.request.post(
    `/api/objects/${created.id}/workflow/transitions/submit_for_review`,
    { data: { comment: 'RWD check' }, headers: bearer },
  );
  expect(submit.status()).toBe(200);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/workflow');
  await expect(page.getByTestId('review-queue-page')).toBeVisible();

  const row = page.getByTestId('review-queue-row').filter({ hasText: sku });
  await expect(row).toBeVisible();

  // 1. No horizontal overflow on the document at mobile width — the table
  //    layout used to blow past the viewport.
  const overflowX = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(overflowX).toBeLessThanOrEqual(1);

  // 2. The approve action is reachable + clickable without a horizontal
  //    scroll (was clipped off-screen in the table layout).
  const approve = row.getByTestId(`queue-approve-${sku}`);
  await approve.scrollIntoViewIfNeeded();
  await expect(approve).toBeInViewport();
  await approve.click();
  await page.getByTestId('queue-confirm').click();
  await expect(row).toHaveCount(0);

  // Cleanup so reruns stay hygienic (approve → published → unpublish).
  await page.request
    .post(`/api/objects/${created.id}/workflow/transitions/unpublish`, { headers: bearer })
    .catch(() => undefined);
});
