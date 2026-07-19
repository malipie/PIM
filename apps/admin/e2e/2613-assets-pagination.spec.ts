import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #2613 — the Multimedia view used `pagination: { mode: 'off' }`, but Refine
 * still sent its default `pageSize=10` to the data provider, silently capping
 * the grid at 10 files with no pager. The view now paginates server-side
 * (default 50/page, PaginationBar below the grid).
 *
 * The spec guarantees > one page of data by uploading unique 1×1 PNGs through
 * the real upload endpoint until the tenant holds at least 55 assets (CI
 * fixtures ship 10; a local dev DB may already hold thousands — then no
 * uploads happen). Uploaded assets are bulk-deleted afterwards so local runs
 * stay clean.
 */

const PAGE_SIZE = 50;
const TARGET_TOTAL = 55;

// Minimal valid 1×1 transparent PNG; unique trailing bytes defeat the
// content-hash dedup while the magic-byte sniff still sees a PNG.
const PNG_BASE = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);

test('#2613 — assets grid paginates past the first page', async ({ page }) => {
  test.setTimeout(180_000);

  await loginAsAdmin(page);
  const refreshResponse = await page.request.post('/api/auth/refresh');
  expect(refreshResponse.status()).toBe(200);
  const accessToken = ((await refreshResponse.json()) as { token: string }).token;
  const authHeaders = { Authorization: `Bearer ${accessToken}`, accept: 'application/json' };

  const countResponse = await page.request.get('/api/assets?itemsPerPage=1', {
    headers: authHeaders,
  });
  expect(countResponse.status()).toBe(200);
  let total = ((await countResponse.json()) as { totalItems?: number }).totalItems ?? 0;

  const uploadedIds: string[] = [];
  const runTag = `e2e-2613-${Date.now()}`;
  for (let i = 0; total + uploadedIds.length < TARGET_TOTAL; i += 1) {
    const buffer = Buffer.concat([PNG_BASE, Buffer.from(`${runTag}-${i}`)]);
    const uploadResponse = await page.request.post('/api/assets/upload', {
      headers: authHeaders,
      multipart: {
        file: { name: `${runTag}-${i}.png`, mimeType: 'image/png', buffer },
      },
    });
    expect(uploadResponse.status(), await uploadResponse.text()).toBe(201);
    const body = (await uploadResponse.json()) as { id: string };
    uploadedIds.push(body.id);
  }
  total += uploadedIds.length;

  try {
    await page.goto('/assets');

    // Header counter shows the FULL result set, not the page length.
    const filesCount = page.getByTestId('files-count');
    await expect(filesCount).not.toHaveText(/^(0|10)$/, { timeout: 15_000 });
    const settledTotal = Number((await filesCount.innerText()).trim());
    expect(settledTotal).toBeGreaterThanOrEqual(TARGET_TOTAL);

    // Page 1 renders exactly one page of tiles.
    const grid = page.getByRole('list', { name: /^(zasoby|assets)$/i });
    await expect(grid.locator('> li')).toHaveCount(Math.min(settledTotal, PAGE_SIZE));

    // The pager is rendered once the set exceeds a page.
    const pager = page.locator('nav').filter({ has: page.locator('#page-size-select') });
    await expect(pager).toBeVisible();

    // Page 2 renders the next slice.
    await pager.getByRole('button', { name: '2', exact: true }).click();
    const expectedSecondPage = Math.min(settledTotal - PAGE_SIZE, PAGE_SIZE);
    await expect(grid.locator('> li')).toHaveCount(expectedSecondPage);

    // Back to page 1 → full page again.
    await pager.getByRole('button', { name: '1', exact: true }).click();
    await expect(grid.locator('> li')).toHaveCount(Math.min(settledTotal, PAGE_SIZE));
  } finally {
    if (uploadedIds.length > 0) {
      const cleanupResponse = await page.request.post('/api/assets/bulk-delete', {
        headers: authHeaders,
        data: { ids: uploadedIds },
      });
      expect(cleanupResponse.status(), await cleanupResponse.text()).toBeLessThan(300);
    }
  }
});
