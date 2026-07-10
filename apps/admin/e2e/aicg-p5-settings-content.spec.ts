import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

/**
 * AICG-P5-04/05 (#2342/#2343) — the AI content settings in
 * Settings → AI: ContentRecipe CRUD (create/edit) and BrandVoiceProfile
 * CRUD with the transactional default swap. Runs against the real
 * backend (plain CRUD, no LLM) and cleans up its artefacts.
 */

const STAMP = `e2e${Date.now().toString(36)}`;

async function openAiSettings(page: Page) {
  await loginAsAdmin(page);
  await page.goto('/settings/ai');
  await expect(page.getByTestId('content-recipes-section')).toBeVisible({ timeout: 30_000 });
}

async function pickCombobox(
  container: ReturnType<Page['getByTestId']>,
  label: RegExp,
  optionText: string,
) {
  const field = container.locator('label', { hasText: label });
  await field.getByRole('button').first().click();
  const popover = field.locator('div.absolute');
  await popover.locator('input[type="text"]').fill(optionText);
  await popover.locator('button', { hasText: optionText }).first().click();
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('i18nextLng', 'pl');
  });
});

test('recipe create + edit persists and passes the axe gate', async ({ page }) => {
  await openAiSettings(page);
  const section = page.getByTestId('content-recipes-section');

  await section.getByTestId('recipe-create').click();
  await page.getByTestId('recipe-code').fill(`rcp_${STAMP}`);
  await page.getByTestId('recipe-name').fill(`Przepis ${STAMP}`);
  await pickCombobox(section, /pole docelowe/i, 'description');
  await page.getByTestId('recipe-seo-meta').fill('155');
  await page.getByTestId('recipe-save').click();

  const row = section.locator('li', { hasText: `Przepis ${STAMP}` });
  await expect(row).toBeVisible({ timeout: 15_000 });

  // Edit: bump the meta budget and confirm it round-trips.
  await row.getByRole('button', { name: new RegExp(`edytuj przepis .*${STAMP}`, 'i') }).click();
  await page.getByTestId('recipe-seo-meta').fill('140');
  await page.getByTestId('recipe-save').click();
  await expect(page.getByTestId('recipe-form')).toHaveCount(0, { timeout: 15_000 });
  await row.getByRole('button', { name: new RegExp(`edytuj przepis .*${STAMP}`, 'i') }).click();
  await expect(page.getByTestId('recipe-seo-meta')).toHaveValue('140');

  // axe gate with the form rendered.
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(
    axe.violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => violation.id),
  ).toEqual([]);

  // Cleanup: cancel the form, delete the recipe.
  await page.getByRole('button', { name: /^anuluj$/i }).click();
  await row.getByRole('button', { name: new RegExp(`usuń przepis .*${STAMP}`, 'i') }).click();
  await expect(row).toHaveCount(0, { timeout: 15_000 });
});

test('setting a new default brand voice clears the previous one', async ({ page }) => {
  await openAiSettings(page);
  const section = page.getByTestId('brand-voice-section');

  // Voice A becomes default.
  await section.getByTestId('voice-create').click();
  await page.getByTestId('voice-name').fill(`Glos A ${STAMP}`);
  await page.getByTestId('voice-tone').fill('rzeczowy, techniczny');
  await page.getByTestId('voice-default-toggle').check();
  await page.getByTestId('voice-save').click();
  const rowA = section.locator('li', { hasText: `Glos A ${STAMP}` });
  await expect(rowA).toBeVisible({ timeout: 15_000 });
  await expect(rowA.getByText(/domyślny/i)).toBeVisible();

  // Voice B takes the default over — A loses the badge (transactional swap).
  await section.getByTestId('voice-create').click();
  await page.getByTestId('voice-name').fill(`Glos B ${STAMP}`);
  await page.getByTestId('voice-tone').fill('swobodny');
  await page.getByTestId('voice-default-toggle').check();
  await page.getByTestId('voice-save').click();
  const rowB = section.locator('li', { hasText: `Glos B ${STAMP}` });
  await expect(rowB).toBeVisible({ timeout: 15_000 });
  await expect(rowB.getByText(/domyślny/i)).toBeVisible();
  await expect(rowA.getByText(/domyślny/i)).toHaveCount(0);

  // Cleanup both voices.
  for (const row of [rowB, rowA]) {
    await row.getByRole('button', { name: /usuń głos/i }).click();
    await expect(row).toHaveCount(0, { timeout: 15_000 });
  }
});
