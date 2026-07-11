import AxeBuilder from '@axe-core/playwright';
import { type BrowserContext, expect, type Page, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, uniqueSku } from './helpers/auth';

/**
 * WFL-P6-02 (#2435) — the cross-role editorial loop that unit tests can't
 * see because it spans two principals. A marketing editor (workflow.view,
 * no approve_reject) and an admin/approver collaborate through the whole
 * lifecycle: submit → reject → fix → resubmit → approve → published →
 * edit-locked → request-unpublish → unpublish. Plus an axe pass on the
 * /workflow hub (both tabs).
 *
 * Two browser contexts hold the two sessions. Cross-role state that isn't
 * the visible assertion is driven through the API (each context mints its
 * own Bearer); the transitions whose VISIBLE effect is the point are
 * driven through the UI.
 */

const MARKETING_EMAIL = `wfl-mkt-${Date.now().toString(36)}@demo.localhost`;
const MARKETING_PASSWORD = 'changeme-marketing-1';

async function bearerFor(page: Page, email: string, password: string): Promise<string> {
  const response = await page.request.post('/api/auth/login', {
    data: { email, password },
    headers: { accept: 'application/json' },
  });
  expect(response.status(), `login ${email}`).toBe(200);
  return ((await response.json()) as { token: string }).token;
}

async function sessionContext(
  browser: import('@playwright/test').Browser,
  email: string,
  password: string,
): Promise<{ context: BrowserContext; page: Page; bearer: string }> {
  const context = await browser.newContext();
  const page = await context.newPage();
  // Force the Polish UI regardless of the seeded profile (local gotcha).
  await page.addInitScript(() => window.localStorage.setItem('i18nextLng', 'pl'));
  const bearer = await bearerFor(page, email, password);
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/dashboard$/);
  return { context, page, bearer };
}

test('editorial loop across marketing and approver, plus axe on the hub', async ({ browser }) => {
  // Admin session first — it provisions the marketing user.
  const admin = await sessionContext(browser, ADMIN_EMAIL, ADMIN_PASSWORD);
  const adminAuth = { authorization: `Bearer ${admin.bearer}` };

  const createUser = await admin.page.request.post('/api/users', {
    data: {
      email: MARKETING_EMAIL,
      password: MARKETING_PASSWORD,
      role_code: 'marketing',
      force_password_change: false,
      send_welcome_email: false,
    },
    headers: { ...adminAuth, 'content-type': 'application/json' },
  });
  expect(createUser.status(), 'provision marketing user').toBe(201);

  const marketing = await sessionContext(browser, MARKETING_EMAIL, MARKETING_PASSWORD);
  const marketingAuth = { authorization: `Bearer ${marketing.bearer}` };

  // Resolve the product ObjectType (admin bearer — marketing has view too).
  const typesResponse = await admin.page.request.get('/api/object_types?itemsPerPage=200', {
    headers: { ...adminAuth, accept: 'application/ld+json' },
  });
  const types = (await typesResponse.json()) as { member?: { id: string; kind: string }[] };
  const productType = (types.member ?? []).find((candidate) => candidate.kind === 'product');
  if (productType === undefined) throw new Error('No product ObjectType seeded.');

  const sku = uniqueSku('WFL-LOOP');
  const created = await marketing.page.request.post('/api/products', {
    data: { code: sku, objectTypeId: productType.id },
    headers: { ...marketingAuth, 'content-type': 'application/ld+json' },
  });
  expect(created.status(), 'marketing creates draft').toBe(201);
  const objectId = ((await created.json()) as { id: string }).id;

  // --- Marketing submits for review through the product-detail control. ---
  await marketing.page.goto(`/products/${objectId}`);
  const marketingControl = marketing.page.getByTestId('workflow-status-control');
  await expect(marketingControl).toBeVisible();
  await marketing.page.getByTestId('workflow-transition-submit_for_review').click();
  await marketing.page.getByTestId('workflow-comment').fill('Loop: gotowe do przeglądu');
  await marketing.page.getByTestId('workflow-confirm').click();
  await expect(marketingControl.getByText(/W przeglądzie|In review/).first()).toBeVisible();

  // --- Approver sees it in the queue and rejects with a comment. ---
  await admin.page.goto('/workflow');
  await expect(admin.page.getByTestId('review-queue-page')).toBeVisible();
  const queueRow = admin.page.getByTestId('review-queue-row').filter({ hasText: sku });
  await expect(queueRow).toBeVisible();
  await expect(queueRow.getByText('Loop: gotowe do przeglądu')).toBeVisible();

  // axe on the review tab before we mutate it.
  const axeReview = await new AxeBuilder({ page: admin.page })
    .include('[data-testid="review-queue-page"]')
    .analyze();
  expect(
    axeReview.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'),
  ).toEqual([]);

  // Reject via the API (the reject dialog itself is covered by wfl-p3-02;
  // here the point is the downstream fix-task the marketing side sees).
  const reject = await admin.page.request.post(
    `/api/objects/${objectId}/workflow/transitions/reject`,
    { data: { comment: 'Uzupełnij opis i EAN' }, headers: adminAuth },
  );
  expect(reject.status(), 'approver rejects').toBe(200);

  // --- Marketing sees the fix task carrying the reviewer comment. ---
  await marketing.page.goto('/workflow');
  await marketing.page.getByRole('tab', { name: /moje zadania|my tasks/i }).click();
  const tasksPanel = marketing.page.getByTestId('tasks-panel');
  await expect(tasksPanel).toBeVisible();
  await expect(tasksPanel.getByText('Uzupełnij opis i EAN')).toBeVisible();

  // Fix + resubmit (draft again after reject).
  await marketing.page.goto(`/products/${objectId}`);
  await expect(marketingControl.getByText(/Szkic|Draft/).first()).toBeVisible();
  await marketing.page.getByTestId('workflow-transition-submit_for_review').click();
  await marketing.page.getByTestId('workflow-comment').fill('Loop: poprawione');
  await marketing.page.getByTestId('workflow-confirm').click();
  await expect(marketingControl.getByText(/W przeglądzie|In review/).first()).toBeVisible();

  // --- Approver approves → published. ---
  const approve = await admin.page.request.post(
    `/api/objects/${objectId}/workflow/transitions/approve`,
    { headers: adminAuth },
  );
  expect(approve.status(), 'approver approves').toBe(200);
  expect(((await approve.json()) as { current_place: string }).current_place).toBe('published');

  // --- Marketing hits the edit lock on the published object and requests
  //     unpublish through the banner. ---
  await marketing.page.goto(`/products/${objectId}`);
  await expect(marketing.page.getByTestId('workflow-lock-banner')).toBeVisible();
  await marketing.page.getByTestId('request-unpublish').click();
  await marketing.page.getByTestId('request-unpublish-comment').fill('Muszę poprawić cenę');
  await marketing.page.getByTestId('request-unpublish-confirm').click();

  // --- Approver gets the request-unpublish task and unpublishes. ---
  const tasksResponse = await admin.page.request.get(`/api/workflow/tasks?object_id=${objectId}`, {
    headers: adminAuth,
  });
  const taskList = (await tasksResponse.json()) as {
    items: { type: string; status: string }[];
  };
  expect(
    taskList.items.some((task) => task.type === 'request_unpublish' && task.status === 'open'),
    'approver has an open request_unpublish task',
  ).toBe(true);

  const unpublish = await admin.page.request.post(
    `/api/objects/${objectId}/workflow/transitions/unpublish`,
    { headers: adminAuth },
  );
  expect(unpublish.status(), 'approver unpublishes').toBe(200);
  expect(((await unpublish.json()) as { current_place: string }).current_place).toBe('draft');

  // --- axe on the tasks tab of the hub. The loop above closed every
  //     admin-owned task (unpublish resolved request_unpublish), so the
  //     tab legitimately renders either rows or the empty state — the
  //     TasksPanel testid only exists in the non-empty branch. ---
  await admin.page.goto('/workflow');
  await admin.page.getByRole('tab', { name: /moje zadania|my tasks/i }).click();
  await expect(
    admin.page
      .getByTestId('tasks-panel')
      .or(admin.page.getByText(/brak otwartych zadań|no open tasks/i)),
  ).toBeVisible();
  const axeTasks = await new AxeBuilder({ page: admin.page })
    .include('[data-testid="workflow-page"]')
    .analyze();
  expect(
    axeTasks.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'),
  ).toEqual([]);

  await admin.context.close();
  await marketing.context.close();
});
