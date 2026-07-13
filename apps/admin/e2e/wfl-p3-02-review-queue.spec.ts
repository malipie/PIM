import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, apiLogin, uniqueSku } from './helpers/auth';

/**
 * WFL-P3-02 (#2424) — the review queue lists submitted objects with the
 * submitter's comment, and an inline approve empties the row. The
 * sidebar Workflow entry routes to the page (comingSoon retired).
 */
test('review queue lists a submitted object and approve clears it', async ({ page }) => {
  // page.request carries only cookies; the API wants a Bearer — mint one
  // explicitly for the setup/cleanup calls (same pattern as spec #1351).
  const loginResponse = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(loginResponse.status()).toBe(200);
  const { token } = (await loginResponse.json()) as { token: string };
  const bearer = { authorization: `Bearer ${token}` };

  await apiLogin(page);

  // Fresh draft submitted via the API with a distinctive comment.
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

  const sku = uniqueSku('WFL-Q');
  // #2558 — required attributes must be present or submit_for_review is
  // blocked; fill them so the object reaches the review queue.
  const createResponse = await page.request.post('/api/objects', {
    data: {
      code: sku,
      objectTypeId: productType.id,
      attributes: {
        sku,
        name: `Queue ${sku}`,
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
    { data: { comment: 'E2E kolejka: sprawdź mnie' }, headers: bearer },
  );
  expect(submit.status()).toBe(200);

  await page.goto('/workflow');
  await expect(page.getByTestId('review-queue-page')).toBeVisible();

  const row = page.getByTestId('review-queue-row').filter({ hasText: sku });
  await expect(row).toBeVisible();
  await expect(row.getByText('E2E kolejka: sprawdź mnie')).toBeVisible();

  await row.getByTestId(`queue-approve-${sku}`).click();
  await page.getByTestId('queue-confirm').click();
  await expect(row).toHaveCount(0);

  // Cleanup: back to draft for rerun hygiene.
  const cleanup = await page.request.post(
    `/api/objects/${created.id}/workflow/transitions/unpublish`,
    { headers: bearer },
  );
  expect(cleanup.status()).toBe(200);
});
