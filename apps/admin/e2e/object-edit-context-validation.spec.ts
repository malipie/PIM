import { expect, test } from '@playwright/test';
import { loginAsAdmin, uniqueSku } from './helpers/auth';

/**
 * Object edit view polish:
 *  - the locale × channel context ("Kontekst edycji") lives in the header next
 *    to the title, and the active attribute-group tab carries an orange
 *    underline;
 *  - a backend value-validation error (number below min) is surfaced with the
 *    attribute NAME instead of its raw code, and the offending field gets the
 *    same red highlight as a required-empty one.
 *
 * Setup builds a `number` attribute with `min: 0` on the Product ObjectType and
 * a product that satisfies the OT's other required fields, so the only thing
 * blocking the save is the min rule. All API calls run through in-browser fetch
 * because Node cannot resolve `pim.localhost`.
 */
test('object edit — context in header, orange active tab, validation shows name + highlight', async ({
  page,
}) => {
  // The save path enforces every required attribute, and which attributes are
  // required depends on the seeded Product ObjectType — that set differs between
  // a long-lived dev stack and CI's fresh seed, so the create here cannot
  // reliably satisfy them in CI (mirrors 1216-email-validation). Runs locally
  // against the seeded stack where the feature is smoke-tested end to end.
  test.skip(Boolean(process.env.CI), 'Seed-dependent required-attribute set; run locally.');
  test.setTimeout(120_000);
  await loginAsAdmin(page);

  const attrCode = `nmin_${uniqueSku('X')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_')}`;
  const sku = uniqueSku('EDIT-OBJ');

  const productId = await page.evaluate(
    async ({ attrCode, sku }) => {
      const headers = async () => {
        const r = await fetch('/api/auth/refresh', { method: 'POST', credentials: 'same-origin' });
        const token = ((await r.json()) as { token: string }).token;
        return {
          Authorization: `Bearer ${token}`,
          accept: 'application/ld+json',
          'content-type': 'application/ld+json',
        };
      };
      const ots = (await (
        await fetch('/api/object_types', { headers: await headers(), credentials: 'same-origin' })
      ).json()) as {
        member?: { id: string; kind: string }[];
        'hydra:member'?: { id: string; kind: string }[];
      };
      const pt = (ots.member ?? ots['hydra:member'] ?? []).find((t) => t.kind === 'product');
      if (pt === undefined) throw new Error('no product OT');
      const attr = (await (
        await fetch('/api/attributes', {
          method: 'POST',
          headers: await headers(),
          credentials: 'same-origin',
          body: JSON.stringify({
            code: attrCode,
            type: 'number',
            label: { pl: 'Pojemność testowa', en: 'Test capacity' },
            validationRules: { min: 0 },
          }),
        })
      ).json()) as { id: string };
      await fetch(`/api/object_types/${pt.id}/attributes/${attr.id}`, {
        method: 'POST',
        headers: await headers(),
        credentials: 'same-origin',
      });
      const prod = (await (
        await fetch('/api/products', {
          method: 'POST',
          headers: await headers(),
          credentials: 'same-origin',
          body: JSON.stringify({
            code: sku,
            objectTypeId: pt.id,
            attributes: { name: `Edit ${sku}`, sku, cena_promocyjna: 10 },
          }),
        })
      ).json()) as { id: string };
      return prod.id;
    },
    { attrCode, sku },
  );

  await page.goto(`/products/${productId}`);
  await expect(page.getByRole('button', { name: /^(zapisz zmiany|save changes)$/i })).toBeVisible();

  // Concern 1 — the edit context (language × channel) sits in the header.
  await expect(page.getByText(/kontekst edycji/i)).toBeVisible();

  // Concern 3 — the active attribute-group tab has the orange underline.
  const activeUnderline = page.locator('[role="tab"][aria-selected="true"] span.bg-orange-500');
  await expect(activeUnderline).toHaveCount(1);

  // Concern 4 — number below min → error names the attribute (not its code) and
  // the field highlights like a required-empty one.
  const input = page.locator(`#attr-${attrCode}`).first();
  await input.scrollIntoViewIfNeeded();
  await input.fill('-4');
  await page.getByRole('button', { name: /^(zapisz zmiany|save changes)$/i }).click();

  // Polish UI → localized message with the attribute NAME (not the code).
  await expect(
    page.getByText(/Atrybut.*Pojemność testowa.*Wartość -4 jest poniżej minimum 0/),
  ).toBeVisible({ timeout: 10_000 });
  await expect(page.getByText(attrCode)).toHaveCount(0);

  const row = input.locator('xpath=ancestor::div[contains(@class,"rounded-xl")][1]');
  await expect(row).toHaveClass(/ring-rose-300/);

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
