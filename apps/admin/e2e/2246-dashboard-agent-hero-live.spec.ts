import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2246 — the dashboard agent hero is LIVE: submit starts a real run
 * (surface 'dashboard') and hands off to the chat sheet; quick-action
 * chips come from GET /api/agent/capabilities (registry-derived, same
 * source as the global Cmd+K suggestions). The API is intercepted so
 * the contract is asserted without a BYOK key.
 */

const RUN_ID = '0197c3b0-0000-7000-8000-00000000dash';

const CAPABILITIES = {
  enabled: true,
  reason: null,
  actions: [
    {
      id: 'create_update_attribute',
      label: { pl: 'Dodaj atrybut', en: 'Add attribute' },
      prompt: { pl: 'Dodaj atrybut [nazwa] typu [typ]', en: 'Add attribute [name] of type [type]' },
    },
    {
      id: 'completeness_report',
      label: { pl: 'Raport kompletności', en: 'Completeness report' },
      prompt: { pl: 'Pokaż raport kompletności produktów', en: 'Show the completeness report' },
    },
  ],
};

function runSummary(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: RUN_ID,
    status: 'awaiting_input',
    surface: 'dashboard',
    intent: 'pokaż raport kompletności produktów',
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

async function stubCapabilities(page: Page) {
  await page.route('**/api/agent/capabilities', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(CAPABILITIES),
    }),
  );
}

async function stubRunDetail(page: Page) {
  await page.route(`**/api/agent/runs/${RUN_ID}`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ...runSummary(),
        context: {},
        error_message: null,
        messages: [],
        tool_calls: [],
      }),
    }),
  );
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    // Pin the Polish copy regardless of the Playwright profile locale.
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('hero submit starts a dashboard-surface run and hands off to the chat sheet', async ({
  page,
}) => {
  await stubCapabilities(page);
  await stubRunDetail(page);

  let capturedBody: Record<string, unknown> | null = null;
  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    capturedBody = route.request().postDataJSON() as Record<string, unknown>;
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify(runSummary()),
    });
  });

  await loginAsAdmin(page);
  await page.goto('/dashboard');

  const input = page.getByTestId('agent-hero-input');
  await expect(input).toBeVisible();
  await input.fill('pokaż raport kompletności produktów');
  await input.press('Enter');

  await expect.poll(() => capturedBody).not.toBeNull();
  const body = capturedBody as unknown as {
    intent: string;
    surface: string;
    context: Record<string, unknown>;
  };
  expect(body.intent).toBe('pokaż raport kompletności produktów');
  expect(body.surface).toBe('dashboard');

  // Hand-off: the chat sheet adopted the run; the hero input cleared.
  await expect(page.getByTestId('agent-run-status')).toBeVisible();
  await expect(input).toHaveValue('');
});

test('chips render from capabilities and fill the prompt without submitting', async ({ page }) => {
  await stubCapabilities(page);

  let posted = false;
  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    posted = true;
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify(runSummary()),
    });
  });

  await loginAsAdmin(page);
  await page.goto('/dashboard');

  const chips = page.getByTestId('agent-hero-chip');
  await expect(chips).toHaveCount(2);
  await expect(chips.first()).toHaveText('Dodaj atrybut');

  await chips.first().click();
  await expect(page.getByTestId('agent-hero-input')).toHaveValue(
    'Dodaj atrybut [nazwa] typu [typ]',
  );
  await expect(page.getByTestId('agent-hero-input')).toBeFocused();
  expect(posted).toBe(false);
});

test('409 (active run) opens the chat sheet on the ongoing conversation', async ({ page }) => {
  await stubCapabilities(page);
  await stubRunDetail(page);

  await page.route('**/api/agent/runs*', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        status: 409,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Conflict',
          status: 409,
          detail: 'Another agent run is already in progress.',
        }),
      });
      return;
    }
    // The hero looks the active run up in the caller's history.
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [runSummary()], page: 1, per_page: 5 }),
    });
  });

  await loginAsAdmin(page);
  await page.goto('/dashboard');

  const input = page.getByTestId('agent-hero-input');
  await input.fill('drugie polecenie w trakcie runa');
  await input.press('Enter');

  // No error surfaces — the sheet opens on the adopted active run.
  await expect(page.getByTestId('agent-run-status')).toBeVisible();
  await expect(page.getByTestId('agent-hero-error')).toHaveCount(0);
});

test('global cmd+k shows the same registry-derived suggestions', async ({ page }) => {
  await stubCapabilities(page);

  await loginAsAdmin(page);
  await page.goto('/dashboard');
  await expect(page.getByTestId('agent-hero-input')).toBeVisible();

  const dialog = page.getByRole('dialog');
  await expect(async () => {
    await page.keyboard.press('ControlOrMeta+k');
    await expect(dialog).toBeVisible({ timeout: 1000 });
  }).toPass({ timeout: 15_000 });

  await expect(dialog.getByRole('button', { name: 'Dodaj atrybut', exact: true })).toBeVisible();
  await expect(
    dialog.getByRole('button', { name: 'Raport kompletności', exact: true }),
  ).toBeVisible();

  // Suggestion click prefills the palette input with the prompt template.
  await dialog.getByRole('button', { name: 'Raport kompletności', exact: true }).click();
  await expect(dialog.getByRole('textbox')).toHaveValue('Pokaż raport kompletności produktów');
});

test('missing BYOK key dims the hero and links to Settings → AI', async ({ page }) => {
  await page.route('**/api/agent/capabilities', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ enabled: false, reason: 'missing_byok_key', actions: [] }),
    }),
  );

  await loginAsAdmin(page);
  await page.goto('/dashboard');

  await expect(page.getByTestId('agent-hero-disabled')).toBeVisible();
  await expect(page.getByTestId('agent-hero-input')).toBeDisabled();
  await expect(page.getByTestId('agent-hero-submit')).toBeDisabled();
  await expect(page.getByRole('link', { name: /Ustawienia → AI/ })).toHaveAttribute(
    'href',
    '/settings/ai',
  );
  await expect(page.getByTestId('agent-hero-chip')).toHaveCount(0);
});
