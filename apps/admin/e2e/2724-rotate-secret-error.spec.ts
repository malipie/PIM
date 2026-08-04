import { expect, type Page, test } from '@playwright/test';

import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * #2724 — a failed webhook-secret rotation must surface an error instead of
 * failing silently (the operator would believe the old secret was revoked).
 *
 * Also exercises the new global Refine notificationProvider baseline: the
 * webhook section renders an INLINE error (errorNotification: false), so
 * exactly one error message may appear — no double-reporting.
 *
 * API setup runs through in-page fetch (browser resolves pim.localhost
 * without the /etc/hosts mapping only CI has).
 */

async function api<T>(
  page: Page,
  method: string,
  url: string,
  token: string | null,
  body?: unknown,
  contentType = 'application/ld+json',
): Promise<{ status: number; body: T }> {
  return page.evaluate(
    async (input) => {
      const headers: Record<string, string> = {};
      if (input.token !== null) headers.Authorization = `Bearer ${input.token}`;
      if (input.body !== undefined) headers['content-type'] = input.contentType;
      const res = await fetch(input.url, {
        method: input.method,
        headers,
        body: input.body === undefined ? undefined : JSON.stringify(input.body),
      });
      let parsed: unknown = null;
      try {
        parsed = await res.json();
      } catch {
        // Empty or non-JSON body — the status is all the caller needs.
      }
      return { status: res.status, body: parsed as never };
    },
    { method, url, token, body, contentType },
  );
}

test('a failed webhook secret rotation shows an error and no fake new secret', async ({ page }) => {
  test.setTimeout(120_000);

  await loginAsAdmin(page);

  const refresh = await api<{ token: string }>(page, 'POST', '/api/auth/refresh', null);
  expect(refresh.status).toBe(200);
  const token = refresh.body.token;

  const ts = uniqueSku('ROT')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_');

  const profileResp = await api<{ id: string }>(page, 'POST', '/api/api_profiles', token, {
    code: `rot_${ts}`,
    name: `Rotate E2E ${ts}`,
    outputFormat: 'json',
    webhookUrl: 'https://example.com/webhook-sink',
  });
  expect(profileResp.status, JSON.stringify(profileResp.body)).toBe(201);
  const profileId = profileResp.body.id;

  // Rotation endpoint blows up server-side.
  await page.route(`**/api_profiles/${profileId}/rotate_webhook_secret`, (route) =>
    route.fulfill({
      status: 500,
      contentType: 'application/problem+json',
      body: JSON.stringify({
        type: 'about:blank',
        title: 'Internal Server Error',
        status: 500,
        detail: 'Symulowany błąd rotacji (E2E).',
      }),
    }),
  );

  await page.goto(`/integrations/api-configurator/${profileId}`);
  await page.getByRole('button', { name: /regeneruj sekret/i }).click();

  // Exactly one visible error message, and no "new secret" panel.
  await expect(page.getByRole('alert')).toHaveCount(1);
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByText(/nowy sekret|webhook_secret_new/i)).toHaveCount(0);
});
