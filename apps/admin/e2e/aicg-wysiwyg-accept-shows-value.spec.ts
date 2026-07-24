import { expect, test } from '@playwright/test';
import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * Regression: after accepting an Ask AI proposal for a rich-text (wysiwyg)
 * field, the generated content must appear in the field immediately — no page
 * reload. The empty Plate editor used to serialise to `<p></p>` and emit a
 * spurious change on mount, dirtying the field; `fieldValue` then returned that
 * stale `<p></p>` in preference to the freshly-refetched value, so the accepted
 * content stayed hidden until a reload cleared the dirty buffer.
 *
 * The LLM is mocked (the one seam e2e legitimately fakes); the mocked approve
 * commits the value for real via the object API so accept()'s refetch reads a
 * genuine fresh reading. CI-skipped: the create must satisfy the seeded Product
 * ObjectType's required attributes, whose set differs on CI's fresh seed.
 */
const RUN_ID = '019f0000-0000-7000-8000-0000000ab5a5';
const HTML = '<p>Wygenerowany opis przez agenta 4711</p>';

test('accepted Ask AI proposal shows in the wysiwyg field without a reload', async ({ page }) => {
  test.skip(Boolean(process.env.CI), 'Seed-dependent required-attribute set; run locally.');
  test.setTimeout(120_000);
  await page.addInitScript(() => window.localStorage.setItem('i18nextLng', 'pl'));
  await loginAsAdmin(page);

  const attrCode = `wys_${uniqueSku('X')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_')}`;
  const label = `Opis ${attrCode}`;
  const sku = uniqueSku('WYS-OBJ');

  const productId = await page.evaluate(
    async ({ attrCode, label, sku }) => {
      const h = async () => {
        const r = await fetch('/api/auth/refresh', { method: 'POST', credentials: 'same-origin' });
        const token = ((await r.json()) as { token: string }).token;
        return {
          Authorization: `Bearer ${token}`,
          accept: 'application/ld+json',
          'content-type': 'application/ld+json',
        };
      };
      const ots = (await (
        await fetch('/api/object_types', { headers: await h(), credentials: 'same-origin' })
      ).json()) as {
        member?: { id: string; kind: string }[];
        'hydra:member'?: { id: string; kind: string }[];
      };
      const pt = (ots.member ?? ots['hydra:member'] ?? []).find((t) => t.kind === 'product');
      const attr = (await (
        await fetch('/api/attributes', {
          method: 'POST',
          headers: await h(),
          credentials: 'same-origin',
          body: JSON.stringify({
            code: attrCode,
            type: 'wysiwyg',
            label: { pl: label, en: label },
          }),
        })
      ).json()) as { id: string };
      await fetch(`/api/object_types/${pt?.id}/attributes/${attr.id}`, {
        method: 'POST',
        headers: await h(),
        credentials: 'same-origin',
      });
      const prod = (await (
        await fetch('/api/products', {
          method: 'POST',
          headers: await h(),
          credentials: 'same-origin',
          body: JSON.stringify({
            code: sku,
            objectTypeId: pt?.id,
            attributes: { name: `W ${sku}`, sku, cena_promocyjna: 10 },
          }),
        })
      ).json()) as { id: string };
      return prod.id;
    },
    { attrCode, label, sku },
  );

  await page.route('**/api/agent/runs', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ id: RUN_ID, status: 'awaiting_approval' }),
    });
  });
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
            attribute_code: attrCode,
            scope_locale: null,
            scope_channel: null,
            before: null,
            after: { value: HTML },
            provenance: 'agent',
          },
        ],
        total: 1,
        page: 1,
        per_page: 50,
      }),
    }),
  );
  await page.route(`**/api/agent/runs/${RUN_ID}/approve`, async (route) => {
    // The real approve commits the value; mirror that so the refetch is genuine.
    await page.evaluate(
      async ({ id, attrCode, HTML }) => {
        const r = await fetch('/api/auth/refresh', { method: 'POST', credentials: 'same-origin' });
        const token = ((await r.json()) as { token: string }).token;
        await fetch(`/api/objects/${id}?locale=pl`, {
          method: 'PATCH',
          headers: {
            Authorization: `Bearer ${token}`,
            'content-type': 'application/merge-patch+json',
          },
          credentials: 'same-origin',
          body: JSON.stringify({ attributes: { [attrCode]: HTML } }),
        });
      },
      { id: productId, attrCode, HTML },
    );
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ id: RUN_ID, status: 'done' }),
    });
  });
  let approved = false;
  await page.route(`**/api/agent/runs/${RUN_ID}`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: RUN_ID,
        status: approved ? 'done' : 'awaiting_approval',
        error_message: null,
        context: {},
        messages: [],
        tool_calls: [],
      }),
    }),
  );

  await page.goto(`/products/${productId}`);
  await expect(page.getByRole('button', { name: /^(zapisz zmiany|save changes)$/i })).toBeVisible();
  await expect(page.locator(`[aria-label="${label}"]`).first()).toBeVisible({ timeout: 15_000 });

  await page.locator(`[data-testid="ask-ai-${attrCode}"]`).click();
  await expect(page.getByTestId('ask-ai-after')).toContainText(
    'Wygenerowany opis przez agenta 4711',
    { timeout: 15_000 },
  );
  approved = true;
  await page.getByRole('button', { name: /akceptuj/i }).click();

  await expect(page.getByTestId('ask-ai-proposal')).toHaveCount(0, { timeout: 15_000 });
  // The core assertion: content appears WITHOUT a reload.
  await expect(page.locator(`[aria-label="${label}"]`).first()).toContainText(
    'Wygenerowany opis przez agenta 4711',
    { timeout: 10_000 },
  );

  await page.evaluate(async (id) => {
    const r = await fetch('/api/auth/refresh', { method: 'POST', credentials: 'same-origin' });
    const token = ((await r.json()) as { token: string }).token;
    await fetch(`/api/objects/${id}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token}` },
      credentials: 'same-origin',
    });
  }, productId);
});
