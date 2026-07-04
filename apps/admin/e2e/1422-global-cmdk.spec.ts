import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * NUI-03 (#1422) — global ⌘K palette: real navigation (static routes +
 * settings sub-pages). Since AGENT-P6-02 (#1975) the agent section is
 * REAL (no MOCK badge): suggestions prefill the input and the query can
 * be sent to the agent. The sidebar pill opens the palette; on the
 * universal list routes the list-scoped palette keeps the shortcut (no
 * double binding).
 */
test('NUI-03 — global palette navigates, agent section is live', async ({ page }) => {
  // Suggestions come from the tool registry via GET /api/agent/capabilities
  // (#2246) — stub it so the assertion is deterministic regardless of the
  // stack's BYOK-key state. Registered BEFORE the dashboard loads: the agent
  // hero fetches capabilities on mount and react-query would cache the real
  // (BYOK-less) response for the palette otherwise.
  await page.route('**/api/agent/capabilities', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        enabled: true,
        reason: null,
        actions: [
          {
            id: 'create_update_attribute',
            label: { pl: 'Dodaj atrybut', en: 'Add attribute' },
            prompt: { pl: 'Dodaj atrybut [nazwa]', en: 'Add attribute [name]' },
          },
        ],
      }),
    }),
  );

  await loginAsAdmin(page);
  await page.goto('/dashboard');
  // Wait for the app shell to hydrate before firing the shortcut.
  await expect(
    page.getByRole('button', { name: /zapytaj agenta|ask the agent/i }).first(),
  ).toBeVisible();

  // Open via keyboard.
  await page.keyboard.press('ControlOrMeta+k');
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();

  // Agent section renders registry-derived suggestions — the MOCK badge is
  // gone since the palette talks to the real agent API (AGENT-P6-02).
  await expect(dialog.getByText(/Dodaj atrybut|Add attribute/)).toBeVisible();
  await expect(dialog.getByText('MOCK', { exact: true })).toHaveCount(0);

  // Type to filter and navigate to settings users.
  await page.keyboard.type('Użytkow');
  const usersEntry = dialog.getByRole('button', { name: /użytkownicy|users/i }).first();
  const hasPl = await usersEntry.isVisible().catch(() => false);
  if (!hasPl) {
    // EN UI — retype the English label.
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('Users');
  }
  await dialog
    .getByRole('button', { name: /użytkownicy|users/i })
    .first()
    .click();
  await expect(page).toHaveURL(/\/settings\/users$/);

  // Sidebar pill re-opens the palette from any page.
  await page
    .getByRole('button', { name: /zapytaj agenta|ask the agent/i })
    .first()
    .click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.keyboard.press('Escape');
});
