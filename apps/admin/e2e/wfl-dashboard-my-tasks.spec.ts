import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, uniqueSku } from './helpers/auth';

/**
 * WFL redesign (#2522) — the dashboard "Moje zadania" widget as the rich
 * task grid: a submitted product surfaces as a review card that the
 * approver (approve_reject holder) can decide inline. Approving from the
 * dashboard moves the object to published, which closes the review task,
 * so the card disappears without leaving the page. Plus an axe pass on
 * the widget.
 */
test('dashboard My Tasks widget: inline approve closes the review task', async ({ page }) => {
  // Force the Polish UI regardless of the seeded profile (local gotcha).
  await page.addInitScript(() => window.localStorage.setItem('i18nextLng', 'pl'));

  const login = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(login.status(), 'admin login').toBe(200);
  const bearer = ((await login.json()) as { token: string }).token;
  const auth = { authorization: `Bearer ${bearer}` };

  const typesResponse = await page.request.get('/api/object_types?itemsPerPage=200', {
    headers: { ...auth, accept: 'application/ld+json' },
  });
  const types = (await typesResponse.json()) as { member?: { id: string; kind: string }[] };
  const productType = (types.member ?? []).find((candidate) => candidate.kind === 'product');
  if (productType === undefined) throw new Error('No product ObjectType seeded.');

  const sku = uniqueSku('WFL-DASH');
  const created = await page.request.post('/api/products', {
    data: { code: sku, objectTypeId: productType.id },
    headers: { ...auth, 'content-type': 'application/ld+json' },
  });
  expect(created.status(), 'create draft product').toBe(201);
  const objectId = ((await created.json()) as { id: string }).id;

  const submit = await page.request.post(
    `/api/objects/${objectId}/workflow/transitions/submit_for_review`,
    { data: { comment: 'Dashboard: do przeglądu' }, headers: auth },
  );
  expect(submit.status(), 'submit for review').toBe(200);

  const tasksResponse = await page.request.get(`/api/workflow/tasks?object_id=${objectId}`, {
    headers: auth,
  });
  const taskList = (await tasksResponse.json()) as { items: { id: string; type: string }[] };
  const reviewTask = taskList.items.find((task) => task.type === 'review');
  if (reviewTask === undefined) throw new Error('No review task created for the submitted object.');

  await page.goto('/dashboard');
  const widget = page.getByTestId('my-tasks-card');
  await expect(widget).toBeVisible();

  // axe on the widget before we mutate it.
  const axe = await new AxeBuilder({ page }).include('[data-testid="my-tasks-card"]').analyze();
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );

  const approve = page.getByTestId(`mytasks-approve-${reviewTask.id}`);
  await expect(approve).toBeVisible();
  await approve.click();
  await page.getByTestId('mytasks-confirm').click();

  // Approving moved the object out of review → the task closed → card gone.
  await expect(page.getByTestId(`mytasks-card-${reviewTask.id}`)).toBeHidden();

  const state = await page.request.get(`/api/objects/${objectId}/workflow`, { headers: auth });
  expect(((await state.json()) as { current_place: string }).current_place).toBe('published');
});
