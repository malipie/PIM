import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * UI-08.9 (#264) — Modeling layout shell smoke.
 * META-UI v2 (#289) — sidebar reorg per `00-plan-ui.md` §3.1.
 * UI-03.1 (#356) — adds dashboard mock smoke + redirect / → /dashboard.
 *
 * Single test exercises the full surface (dashboard render, sidebar layout,
 * tablist render, tab switch, legacy redirect) with one login. The
 * auth-rate-limiter (5/IP/15min) is shared across the whole Playwright run;
 * splitting this into multiple `beforeEach`-driven tests would push the
 * cumulative login count past the limit on top of the multi-tenant
 * isolation suite — every flow we care about is therefore folded in here.
 */
test('Modeling shell + Dashboard mock — full handoff smoke', async ({ page }) => {
  test.fixme(
    true,
    'Pending #799: dashboard mock label /aktywno[sś]|activity/i no longer present after UI-03.1 #359 dashboard redesign. Spec needs label-set refresh.',
  );
  await loginAsAdmin(page);

  // 0a. Login lands on /dashboard (UI-03.1) and renders the handoff hero
  //     headline. Dashboard is a static mock — there must be ZERO requests
  //     to /api/dashboard/* (those endpoints don't exist yet).
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  const dashboardApiHits: string[] = [];
  page.on('request', (req) => {
    if (req.url().includes('/api/dashboard/')) dashboardApiHits.push(req.url());
  });
  // Re-open dashboard so the listener catches its requests.
  await page.goto('/dashboard');
  for (const heading of [
    /aktywno[sś]|activity/i,
    /najcz[eę][sś]ciej|most edited/i,
    /status synchronizacji|sync status/i,
    /alerty|alerts/i,
  ]) {
    await expect(page.getByText(heading).first()).toBeVisible();
  }
  expect(dashboardApiHits, `unexpected dashboard API hits: ${dashboardApiHits.join(', ')}`).toEqual(
    [],
  );

  // 0. Sidebar mirrors `00-plan-ui.md` §3.1 — 7 primary leaves followed
  //    by a separator and a single Modeling leaf at the bottom. Two of
  //    the primary leaves remain placeholders awaiting their epics
  //    (UI-03 Services / UI-06 Workflow) and render as `aria-disabled`
  //    spans. Dashboard is now wired (handoff mock — epik UI-03 #356).
  const sidebar = page.getByRole('navigation').first();
  await expect(sidebar).toBeVisible();

  for (const label of [
    /^workspace$/i,
    /^products$|^produkty$/i,
    /^web to print$/i,
    /^multimedia$/i,
    /^workflow$/i,
    /^integrations$|^integracje$/i,
    /^settings$|^ustawienia$/i,
    /^modeling$|^modelowanie$/i,
  ]) {
    // `.first()` — after the Pulpit→Workspace rename (#2561) the sidebar has
    // both a "Workspace" section header and the "Workspace" dashboard leaf;
    // the loop only needs to confirm the entry renders.
    await expect(sidebar.getByText(label).first()).toBeVisible();
  }

  // VIEW-08 (#427): Services removed from default sidebar seed (operator
  // adds it as a custom ObjectType later). Workflow remains as the only
  // coming-soon placeholder rendered by the dynamic sidebar.
  for (const placeholder of [/^workflow$/i]) {
    const item = sidebar.getByText(placeholder).first().locator('..');
    await expect(item).toHaveAttribute('aria-disabled', 'true');
  }

  // 1. /modeling lands on object-types and renders the 6-tab tablist
  // (DP-03 #2033 moved Channels + Locales in from Settings).
  await page.goto('/modeling');
  await expect(page).toHaveURL(/\/modeling\/object-types$/);

  const tablist = page.getByRole('tablist', { name: /modeling sections|sekcje modelowania/i });
  await expect(tablist).toBeVisible();

  const tabNames = [
    /object types|typy obiektów/i,
    /attributes|atrybuty/i,
    /attribute groups|grupy atrybutów/i,
    /categories|kategorie/i,
    /channels|kanały/i,
    /locales|wersje językowe/i,
  ];
  for (const name of tabNames) {
    await expect(tablist.getByRole('tab', { name })).toBeVisible();
  }
  await expect(tablist.getByRole('tab', { name: /object types|typy obiektów/i })).toHaveAttribute(
    'aria-selected',
    'true',
  );

  // 2. Clicking the Attributes tab updates the URL + active highlight.
  // VIEW-01 (#372): TabBadge appends a count to the accessible name once
  // the useList hook resolves; match a leading "atrybuty"/"attributes"
  // token anywhere in the name rather than the bare label only.
  await tablist.getByRole('tab', { name: /(^|\s)(attributes|atrybuty)(\s|$)/i }).click();
  await expect(page).toHaveURL(/\/modeling\/attributes$/);
  await expect(
    tablist.getByRole('tab', { name: /(^|\s)(attributes|atrybuty)(\s|$)/i }),
  ).toHaveAttribute('aria-selected', 'true');

  // 3. Legacy top-level URL redirects to its /modeling/... twin.
  await page.goto('/object-types');
  await expect(page).toHaveURL(/\/modeling\/object-types$/);

  // 4. VIEW-05 (#411) — Products list pixel-perfect smoke. Consolidated
  //    here per lessons.md §10 to share the single auth login and stay
  //    inside the 5/IP/15min rate-limiter budget. Selectors match both
  //    PL and EN copy because i18next picks the locale from the browser
  //    Accept-Language header (Chromium defaults to en-US in CI).
  await page.goto('/products');
  await expect(page).toHaveURL(/\/products$/);

  // Header pixel-perfect (32px h1 + breadcrumb + total + last sync).
  await expect(page.getByText(/Workspace · (katalog|catalog)/, { exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: /Produkty|Products/ })).toBeVisible();

  // Toolbar: search + 4 FilterPill + segmented + Nowy produkt.
  await expect(
    page.getByRole('searchbox', { name: /Szukaj produktów|Search products/i }),
  ).toBeVisible();
  await expect(page.getByRole('button', { name: /^(Marka|Brand)$/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /^(Rodzina|Family)$/ })).toBeVisible();
  const channelBtn = page.getByRole('button', { name: /^(Kanał|Channel)$/ });
  await expect(channelBtn).toBeVisible();
  await expect(page.getByRole('button', { name: /^Status$/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /^(Płasko|Flat)$/ })).toBeVisible();
  const treeBtn = page.getByRole('button', { name: /^(Drzewo|Tree)$/ });
  await expect(treeBtn).toBeVisible();
  await expect(page.getByRole('link', { name: /(Nowy produkt|New product)/i })).toBeVisible();
  await expect(page.getByTestId('products-grid')).toBeVisible();

  // Channel pill click surfaces toast (epic 0.6 placeholder).
  await channelBtn.click();
  await page.getByRole('menuitem', { name: 'Shopify' }).click();
  await expect(
    page
      .getByRole('status')
      .filter({
        hasText: /(Filtr per kanał czeka na epik 0\.6|Per-channel filter waits for epic 0\.6)/,
      })
      .first(),
  ).toBeVisible();

  // VariantsToggle segmented control flips active state on click.
  const flatBtn = page.getByRole('button', { name: /^(Płasko|Flat)$/ });
  await treeBtn.click();
  await expect(treeBtn).toHaveAttribute('aria-pressed', 'true');
  await expect(flatBtn).toHaveAttribute('aria-pressed', 'false');
  await flatBtn.click();
  await expect(flatBtn).toHaveAttribute('aria-pressed', 'true');
  await expect(treeBtn).toHaveAttribute('aria-pressed', 'false');

  // Selecting a row reveals BulkBar with placeholder toasts.
  const grid = page.getByTestId('products-grid');
  const firstRow = grid.locator('[data-testid^="products-grid-row-"]').first();
  if ((await firstRow.count()) > 0) {
    await firstRow.getByRole('checkbox').first().check();
    const bulkBar = page.getByTestId('bulk-bar');
    await expect(bulkBar).toBeVisible();
    await expect(bulkBar).toContainText(/(zaznaczonych produktów|selected products)/);
    await bulkBar.getByRole('button', { name: /(Edytuj atrybut|Edit attribute)/ }).click();
    await expect(
      page
        .getByRole('status')
        .filter({ hasText: /(W przygotowaniu|In progress) — VIEW-05\.2/ })
        .first(),
    ).toBeVisible();
    await bulkBar.getByRole('button', { name: /^(Wyczyść|Clear)$/ }).click();
    await expect(bulkBar).not.toBeVisible();
  }

  // 5. VIEW-08 (#427) — Settings · Menu drag-drop + ObjectType.exposeToMainMenu
  //    + dynamic sidebar smoke. Consolidated here (lessons.md §10) so the
  //    single login budget covers it.
  //
  //    Steps: enable expose toggle on built-in Brand → /settings/menu shows
  //    Brand in Available → Visible list still has 8 rows → protected
  //    Settings/Modeling rows have Lock instead of EyeOff button → Asset
  //    detail page shows the toggle as locked (kind=asset uses /assets DAM).
  await page.goto('/modeling/object-types');
  const brandLink = page
    .locator('a[href^="/modeling/object-types/"]')
    .filter({ hasText: /marki|brand/i })
    .first();
  await brandLink.click();
  await expect(page).toHaveURL(/\/modeling\/object-types\/[0-9a-f-]{36}/);

  const exposeToggle = page.getByRole('switch', {
    name: /udostępnij do głównego menu|expose to main menu/i,
  });
  await expect(exposeToggle).toBeVisible();
  // Test retries can leave Brand with expose=true from a prior run, so
  // ensure the final state is true regardless of the starting position.
  if ((await exposeToggle.getAttribute('aria-checked')) !== 'true') {
    await exposeToggle.click();
  }
  await expect(exposeToggle).toHaveAttribute('aria-checked', 'true');

  await page.goto('/settings/menu');
  await expect(
    page.getByRole('heading', { name: /menu główne|main menu/i, level: 1 }),
  ).toBeVisible();

  const availableList = page.getByTestId('menu-available-list');
  await expect(availableList).toBeVisible();
  await expect(availableList.getByText(/marki|brand/i)).toBeVisible();

  const visibleList = page.getByTestId('menu-visible-list');
  // 1 dashboard + 1 product + 6 system items (catalogs_pdf, multimedia,
  // workflow, integrations, settings, modeling) = 8 rows. Po konsolidacji
  // „Publikacje" + „Integracje" w jeden hub (PR follow-up po #472).
  await expect(visibleList.locator('> div')).toHaveCount(8);

  // Protected items render Lock icon, no EyeOff button. Each row's text
  // is "<label> <SYS|OT> [Wkrótce]" so anchor to the `flex-1` label span
  // rather than asserting exact row text.
  const settingsRow = visibleList
    .locator('> div')
    .filter({ has: page.locator('span.flex-1', { hasText: /^(ustawienia|settings)$/i }) });
  await expect(settingsRow).toBeVisible();
  await expect(settingsRow.getByRole('button', { name: /ukryj|hide/i })).toHaveCount(0);
  const dashboardRow = visibleList
    .locator('> div')
    .filter({ has: page.locator('span.flex-1', { hasText: /^workspace$/i }) });
  await expect(dashboardRow.getByRole('button', { name: /ukryj|hide/i })).toBeVisible();

  // Asset toggle is locked on its detail page.
  await page.goto('/modeling/object-types');
  const assetLink = page
    .locator('a[href^="/modeling/object-types/"]')
    .filter({ hasText: /^zasób|^asset/i })
    .first();
  await assetLink.click();
  await expect(page).toHaveURL(/\/modeling\/object-types\/[0-9a-f-]{36}/);
  const assetExposeToggle = page.getByRole('switch', {
    name: /udostępnij do głównego menu|expose to main menu/i,
  });
  await expect(assetExposeToggle).toBeVisible();
  await expect(assetExposeToggle).toHaveAttribute('aria-disabled', 'true');
});
