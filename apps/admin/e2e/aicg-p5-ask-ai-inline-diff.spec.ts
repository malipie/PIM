import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * AICG-P5-01/02 (#2339/#2340) — the "Ask AI" affordance on text fields
 * and the inline proposal diff. The agent API is intercepted (the same
 * convention as 2246-dashboard-agent-hero-live.spec.ts): CI has no BYOK
 * key, and the LLM is the one seam e2e legitimately mocks — everything
 * else (login, product form, field wiring) runs against the real stack.
 */

const RUN_ID = '019f0000-0000-7000-8000-00000000a5a5';

function runSummary(overrides: Record<string, unknown> = {}) {
  return {
    id: RUN_ID,
    status: 'planning',
    surface: 'chat',
    intent: 'aicg e2e',
    model: null,
    pending_change_batch_id: null,
    affected_count: null,
    bulk_operation_id: null,
    tokens_input: 0,
    tokens_output: 0,
    cost_usd: '0.000000',
    approved_by: null,
    approved_at: null,
    started_at: new Date().toISOString(),
    completed_at: null,
    ...overrides,
  };
}

function runDetail(status: string, overrides: Record<string, unknown> = {}) {
  return {
    ...runSummary({ status }),
    context: {},
    error_message: null,
    messages: [],
    tool_calls: [],
    ...overrides,
  };
}

async function openFirstProduct(page: Page) {
  await page.goto('/products');
  const firstRow = page.locator('[data-testid^="products-grid-row-"]').first();
  await expect(firstRow).toBeVisible({ timeout: 30_000 });
  await firstRow.getByRole('link').first().click();
  await expect(page).toHaveURL(/\/products\/[0-9a-f-]{36}/, { timeout: 30_000 });
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('Ask AI on a text field starts a run with the field context and the accepted proposal resolves', async ({
  page,
}) => {
  let runStatus = 'planning';
  let capturedStart: Record<string, unknown> | null = null;
  let approved = false;

  await page.route(`**/api/agent/runs/${RUN_ID}/plan**`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        items: [
          {
            id: '019f0000-0000-7000-8000-00000000p1a1',
            change_type: 'value',
            status: 'pending',
            target_object_id: null,
            target_object_code: null,
            target_object_name: null,
            attribute_code: capturedStart
              ? ((capturedStart.context as Record<string, unknown>).target_attribute as string)
              : 'description',
            scope_locale: null,
            scope_channel: null,
            before: null,
            after: { value: 'Wygenerowany opis e2e — czarne botki ze skóry.' },
            provenance: 'agent',
          },
        ],
        total: 1,
        page: 1,
        per_page: 50,
      }),
    }),
  );
  await page.route(`**/api/agent/runs/${RUN_ID}/approve`, (route) => {
    approved = true;
    runStatus = 'done';
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(runSummary({ status: 'done' })),
    });
  });
  await page.route(`**/api/agent/runs/${RUN_ID}`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(runDetail(runStatus)),
    }),
  );
  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    capturedStart = route.request().postDataJSON() as Record<string, unknown>;
    // First poll sees planning, the next one the materialized proposal.
    setTimeout(() => {
      runStatus = 'awaiting_approval';
    }, 500);
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify(runSummary()),
    });
  });

  await loginAsAdmin(page);
  await openFirstProduct(page);

  // The affordance renders on editable text-family fields.
  const askButton = page.locator('[data-testid^="ask-ai-"]').first();
  await expect(askButton).toBeVisible({ timeout: 30_000 });
  const askedCode = (await askButton.getAttribute('data-testid'))?.replace('ask-ai-', '');
  await askButton.click();

  // The run started with the field's context.
  await expect.poll(() => capturedStart !== null).toBeTruthy();
  const context = (capturedStart as unknown as { context: Record<string, unknown> }).context;
  expect(context.target_attribute).toBe(askedCode);
  expect(typeof context.product_id).toBe('string');

  // Progress -> inline diff with the generated copy.
  const proposal = page.getByTestId('ask-ai-proposal');
  await expect(proposal).toBeVisible();
  await expect(page.getByTestId('ask-ai-after')).toContainText('Wygenerowany opis e2e', {
    timeout: 15_000,
  });

  // axe gate (WCAG A/AA) with the proposal card rendered — the affordance
  // and the diff must introduce no serious/critical violations.
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(
    axe.violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => violation.id),
  ).toEqual([]);

  // Accept resolves through the run-approval API and the card clears.
  await page.getByRole('button', { name: /akceptuj/i }).click();
  await expect.poll(() => approved).toBeTruthy();
  await expect(proposal).toHaveCount(0, { timeout: 15_000 });
});

test('a failed run surfaces an inline error the operator can dismiss', async ({ page }) => {
  await page.route(`**/api/agent/runs/${RUN_ID}`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(runDetail('error', { error_message: 'Brak przepisu dla tego pola.' })),
    }),
  );
  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify(runSummary({ status: 'error' })),
    });
  });

  await loginAsAdmin(page);
  await openFirstProduct(page);

  const askButton = page.locator('[data-testid^="ask-ai-"]').first();
  await expect(askButton).toBeVisible({ timeout: 30_000 });
  await askButton.click();

  const proposal = page.getByTestId('ask-ai-proposal');
  await expect(proposal).toBeVisible();
  await expect(proposal).toContainText(/nie powiodło się/i, { timeout: 15_000 });
  await expect(proposal).toContainText('Brak przepisu dla tego pola.');

  await page.getByRole('button', { name: /zamknij/i }).click();
  await expect(proposal).toHaveCount(0);
});
