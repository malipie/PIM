import { expect, test } from '@playwright/test';

import { ADMIN_PASSWORD, loginAsAdmin } from './helpers/auth';

/**
 * Panel operatora widzi WYŁĄCZNIE konto z rolą `platform_operator` — rola jest
 * celowo niedostępna dla właściciela tenanta (AUD-003), więc zwykły
 * `admin@demo.localhost` dostaje tu 404/403.
 */
const PLATFORM_OPERATOR_EMAIL = 'platform-operator@cortex.localhost';

/**
 * TNT-P4-08 (#2909) — zawieszenie, wznowienie i skasowanie z panelu.
 *
 * Test celuje w to, czego #2909 realnie dotknęło po stronie przeglądarki:
 * menu akcji przebudowane pod okno postępu. Regresja byłaby cicha —
 * przycisk, który przestał wysyłać żądanie, wygląda dokładnie tak samo.
 *
 * Ekran postępu wymaga działającej kolejki provisioningu (instancja
 * platformowa), więc **nie jest tu sprawdzany**: na stacku deweloperskim
 * endpoint odpowiada 200 bez identyfikatora zlecenia i okno się nie otwiera.
 * Sprawdzenie propagacji na stack leży w `TenantLifecycleProvisioningTest`
 * i w testach provisionera.
 *
 * Operacje idą na tenancie utworzonym w tym teście, nigdy na `demo` ani
 * `acme` — zawieszenie cudzego tenanta wywróciłoby pozostałe specyfikacje.
 */
test('cykl życia tenanta: zawieś → przywróć → usuń, każda akcja zmienia status', async ({
  page,
}) => {
  const suffix = Date.now().toString(36);
  const code = `e2e-tnt-${suffix}`;

  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  await loginAsAdmin(page, PLATFORM_OPERATOR_EMAIL, ADMIN_PASSWORD);
  await page.goto('/admin/tenants');

  // ── Tenant jednorazowy, żeby nie ruszać cudzych danych ──────────────────
  await page.getByRole('button', { name: /nowy tenant|new tenant/i }).click();
  await page.locator('#tenant-code').fill(code);
  await page.locator('#tenant-name').fill(`E2E ${suffix}`);
  await page.locator('#tenant-owner-email').fill(`owner-${suffix}@e2e.localhost`);
  await page
    .getByRole('button', { name: /utwórz|create/i })
    .last()
    .click();

  await page.getByRole('button', { name: /zamknij|close/i }).click();

  const row = page.getByRole('row').filter({ hasText: code });
  await expect(row).toBeVisible({ timeout: 15_000 });
  await expect(row).toContainText(/aktywny|active/i);

  // ── Zawieś ──────────────────────────────────────────────────────────────
  page.once('dialog', (dialog) => void dialog.accept());
  await row.getByRole('button', { name: /akcje|actions/i }).click();
  await page.getByRole('menuitem', { name: /zawieś|suspend/i }).click();

  await expect(page.getByRole('row').filter({ hasText: code })).toContainText(
    /zawieszony|suspended/i,
    { timeout: 15_000 },
  );

  // ── Przywróć ────────────────────────────────────────────────────────────
  await page
    .getByRole('row')
    .filter({ hasText: code })
    .getByRole('button', { name: /akcje|actions/i })
    .click();
  await page.getByRole('menuitem', { name: /przywróć|reactivate/i }).click();

  await expect(page.getByRole('row').filter({ hasText: code })).toContainText(/aktywny|active/i, {
    timeout: 15_000,
  });

  // ── Usuń (soft) — wiersz zostaje, ale bez menu akcji ─────────────────────
  page.once('dialog', (dialog) => void dialog.accept());
  await page
    .getByRole('row')
    .filter({ hasText: code })
    .getByRole('button', { name: /akcje|actions/i })
    .click();
  await page.getByRole('menuitem', { name: /usuń|delete/i }).click();

  await expect(page.getByRole('row').filter({ hasText: code })).toContainText(/usunięty|deleted/i, {
    timeout: 15_000,
  });

  // Zawężone świadomie: panel na stacku deweloperskim sypie 401 przy
  // bootstrapie sesji i 403 z zasobów, których operator platformy nie ma
  // (to jest stan istniejący, nie regresja tej zmiany). Bramką jest tu brak
  // błędu serwera na trasie akcji cyklu życia.
  expect(consoleErrors.filter((line) => /status of 5\d\d/.test(line))).toEqual([]);
});
