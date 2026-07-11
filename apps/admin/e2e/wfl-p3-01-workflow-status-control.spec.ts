import { expect, test } from '@playwright/test';

import { apiLogin, uniqueSku } from './helpers/auth';

/**
 * Pakiet E (WFL-P3-01 #2423 + WFL-P3-05 #2427) — the editorial state
 * control on the product header: status pill mirrors the machine,
 * transition buttons come from the guard-aware discovery endpoint,
 * applying a transition with a comment moves the pill, and the history
 * sheet lists the applied transitions with their comments.
 */
test('workflow control: submit -> approve loop with history on product detail', async ({
  page,
}) => {
  await apiLogin(page);

  // Fresh draft via the API the admin itself uses (cookie-authed context).
  const typesResponse = await page.request.get('/api/object_types');
  const types = (await typesResponse.json()) as {
    'hydra:member'?: { id: string; kind: string }[];
    member?: { id: string; kind: string }[];
  };
  const productType = (types['hydra:member'] ?? types.member ?? []).find(
    (candidate) => candidate.kind === 'product',
  );
  if (productType === undefined) throw new Error('No product ObjectType seeded.');

  const createResponse = await page.request.post('/api/objects', {
    data: { code: uniqueSku('WFL-E2E'), objectTypeId: productType.id },
    headers: { 'content-type': 'application/ld+json' },
  });
  expect(createResponse.status()).toBe(201);
  const created = (await createResponse.json()) as { id: string };

  await page.goto(`/products/${created.id}`);
  const control = page.getByTestId('workflow-status-control');
  await expect(control).toBeVisible();
  await expect(control.getByText(/Szkic|Draft/).first()).toBeVisible();

  // Submit for review with a comment.
  await page.getByTestId('workflow-transition-submit_for_review').click();
  await page.getByTestId('workflow-comment').fill('E2E: proszę o przegląd');
  await page.getByTestId('workflow-confirm').click();
  await expect(control.getByText(/W przeglądzie|In review/).first()).toBeVisible();

  // Approve — admin holds workflow.approve_reject.
  await page.getByTestId('workflow-transition-approve').click();
  await page.getByTestId('workflow-confirm').click();
  await expect(control.getByText(/Opublikowany|Published/).first()).toBeVisible();

  // History sheet lists both transitions, newest first, with the comment.
  await page.getByTestId('workflow-history-open').click();
  const history = page.getByTestId('workflow-history-list');
  await expect(history.getByText(/Zatwierdź|Approve/).first()).toBeVisible();
  await expect(history.getByText('E2E: proszę o przegląd')).toBeVisible();

  // Cleanup: back to draft so reruns start from a known place.
  const cleanup = await page.request.post(
    `/api/objects/${created.id}/workflow/transitions/unpublish`,
  );
  expect(cleanup.status()).toBe(200);
});
