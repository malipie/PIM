import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * DP-05 (#2035) — validation-rules editor in the attribute form.
 *
 * Single test, one login. Creates a throwaway text attribute via the
 * browser-side API (token minted from the HttpOnly refresh cookie), sets
 * min/max length + a regex through the new "Reguły walidacji" section,
 * checks the live pattern tester, saves, and verifies the rules landed in
 * the attribute payload (the engine that enforces them is covered by
 * backend suites — AttributeValueValidationApiTest). Cleans up after.
 */

async function browserApi(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async (args: { method: string; path: string; body?: unknown }) => {
      const refresh = await fetch('/api/auth/refresh', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { accept: 'application/json' },
      });
      const { token } = (await refresh.json()) as { token: string };
      const res = await fetch(args.path, {
        method: args.method,
        headers: {
          authorization: `Bearer ${token}`,
          accept: 'application/ld+json',
          ...(args.body !== undefined ? { 'content-type': 'application/ld+json' } : {}),
        },
        ...(args.body !== undefined ? { body: JSON.stringify(args.body) } : {}),
      });
      const text = await res.text();
      let parsed: unknown = null;
      try {
        parsed = text === '' ? null : JSON.parse(text);
      } catch {
        parsed = text;
      }
      return { status: res.status, body: parsed };
    },
    { method, path, body },
  );
}

test('DP-05 — operator configures validation rules on a text attribute', async ({ page }) => {
  await loginAsAdmin(page);

  const code = `dp05_rules_${Date.now().toString(36)}`;
  const created = await browserApi(page, 'POST', '/api/attributes', {
    code,
    label: { pl: code, en: code },
    type: 'text',
    required: false,
    filterable: false,
  });
  expect(created.status, JSON.stringify(created.body)).toBeLessThan(300);
  const attributeId = (created.body as { id: string }).id;

  try {
    await page.goto(`/modeling/attributes/${attributeId}`);
    await expect(page.getByText(/reguły walidacji|validation rules/i).first()).toBeVisible();

    // Fill length bounds + regex.
    await page.getByLabel(/min\. długość|min length/i).fill('2');
    await page.getByLabel(/maks\. długość|max length/i).fill('5');
    await page.getByLabel(/wzorzec|pattern/i).fill('^[a-z]+$');

    // Live tester: lowercase matches, uppercase does not — no API round-trip.
    const sample = page.getByPlaceholder(/przetestuj|test a sample/i);
    await sample.fill('abc');
    await expect(page.getByText(/^(pasuje|matches)$/i)).toBeVisible();
    await sample.fill('ABC');
    await expect(page.getByText(/nie pasuje|no match/i)).toBeVisible();

    // Save via the dirty-bar CTA (the header renders a twin — take the last,
    // the sticky bottom bar) and confirm persistence.
    await page
      .getByRole('button', { name: /zapisz zmiany|save changes/i })
      .last()
      .click();
    await expect
      .poll(async () => {
        const fetched = await browserApi(page, 'GET', `/api/attributes/${attributeId}`);
        const rules = (fetched.body as { validationRules?: Record<string, unknown> })
          .validationRules;
        return rules ? JSON.stringify(rules) : '';
      })
      .toContain('"max_length":5');

    const fetched = await browserApi(page, 'GET', `/api/attributes/${attributeId}`);
    const rules = (fetched.body as { validationRules?: Record<string, unknown> }).validationRules;
    expect(rules?.min_length).toBe(2);
    expect(rules?.pattern).toBe('^[a-z]+$');
  } finally {
    await browserApi(page, 'DELETE', `/api/attributes/${attributeId}`);
  }
});
