import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * TNT-P4-06 (#2907) — pole subdomeny w formularzu zakładania instancji.
 *
 * Test celuje w to, co operator widzi ZANIM cokolwiek powstanie: podpowiedź
 * z kodu, pełny adres pod polem i odmowa dla nazwy o złym kształcie. Ekran
 * postępu wymaga działającej kolejki provisioningu (instancja platformowa),
 * więc nie jest tu sprawdzany — na stacku deweloperskim endpoint idzie
 * ścieżką lokalną i zlecenie nie powstaje.
 */
test('subdomena: podpowiedź z kodu, podgląd adresu i odmowa dla złego kształtu', async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.goto('/admin/tenants');

  await page.getByRole('button', { name: /nowy tenant|new tenant/i }).click();

  const code = page.locator('#tenant-code');
  const subdomain = page.locator('#tenant-subdomain');
  await expect(subdomain).toBeVisible();

  // Podkreślenie jest dozwolone w kodzie, ale nie w subdomenie — podpowiedź
  // ma je zamienić, zamiast zmuszać operatora do przepisywania.
  await code.fill('acme_corp');
  await expect(subdomain).toHaveValue('acme-corp');

  // Pełny adres pod polem: sama subdomena bez kontekstu bywa myląca.
  await expect(page.getByText('acme-corp.app.harmonpim.pl')).toBeVisible();

  // Nadpisanie ręczne przestaje śledzić kod.
  await subdomain.fill('inna-nazwa');
  await code.fill('acme_corp_2');
  await expect(subdomain).toHaveValue('inna-nazwa');

  // Zły kształt: myślnik na końcu. Komunikat ma mówić, CO poprawić.
  await subdomain.fill('acme-');
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByText('acme-.app.harmonpim.pl')).toHaveCount(0);

  const a11y = await new AxeBuilder({ page }).include('form').analyze();
  expect(a11y.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );
});
