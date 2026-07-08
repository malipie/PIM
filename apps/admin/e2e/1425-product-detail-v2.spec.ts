import { expect, test } from '@playwright/test';

import { ADMIN_EMAIL, ADMIN_PASSWORD, apiLogin } from './helpers/auth';

/**
 * NUI-06 (#1425) — design-parity guard for the unified product detail
 * card. The structural v2 work shipped via the detail-unification
 * series (#1434, #1351, #1440, #1442) and the NUI-07 de-violet sweep;
 * this spec pins the design surface from `produkty/detail-view.jsx`:
 * header (back + breadcrumb + actions + completeness ring), tabs with
 * counts, collapsible attribute groups with fill counters, provenance
 * badges, the locale/channel switcher and the right rail.
 */
test('NUI-06 — product detail renders the v2 design surface', async ({ page }) => {
  const loginResponse = await page.request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
    headers: { accept: 'application/json' },
  });
  expect(loginResponse.status()).toBe(200);
  const { token } = (await loginResponse.json()) as { token: string };
  await apiLogin(page);

  const productsResponse = await page.request.get('/api/products?itemsPerPage=1', {
    headers: { authorization: `Bearer ${token}`, accept: 'application/ld+json' },
  });
  const body = (await productsResponse.json()) as { member?: Array<{ id: string }> };
  const productId = body.member?.[0]?.id;
  test.skip(productId === undefined, 'No products in this environment seed');

  await page.goto(`/products/${productId}`);
  await page.waitForURL(/\/products\/[0-9a-f-]{8,}/, { timeout: 15_000 });

  // Header: breadcrumb back-link, action buttons, completeness ring (%).
  // #2317 — the mock "Podgląd" button was removed; only real actions remain.
  await expect(page.getByRole('link', { name: /produkty|products/i }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: /duplikuj|duplicate/i })).toBeVisible();
  await expect(page.getByText(/^\d+%$/).first()).toBeVisible();

  // Locale/channel switcher present (scope-aware reads, #1225/#1269).
  await expect(page.getByRole('button', { name: /język|language/i }).first()).toBeVisible();

  // Tabs with counts render (attribute groups as tabs + special tabs).
  // #2317 — the mock "Historia" tab was removed.
  await expect(page.getByText(/media|multimedia/i).first()).toBeVisible();

  // Attribute group card: fill counter + provenance badge on rows.
  await expect(page.getByText(/\d+ \/ \d+|filled/i).first()).toBeVisible();
  await expect(page.getByText(/manual|import|agent|integration/i).first()).toBeVisible();

  // Right rail: effective model + variants cards.
  await expect(page.getByText(/effective model|efektywny model|model efektywny/i)).toBeVisible();
  await expect(page.getByText(/variants|warianty/i).first()).toBeVisible();
});
