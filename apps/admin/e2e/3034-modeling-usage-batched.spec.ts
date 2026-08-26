import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * #3034 — the modeling list pages must read their `where-used` counters in one
 * batched request instead of one request per row.
 *
 * The regression this guards against is not a wrong pixel, it is request
 * volume: the attributes list used to fire one `/usage` call per attribute plus
 * one `/options` call per select attribute. On the production tenant that was
 * ~77 requests dispatched at once, which filled the FrankenPHP worker pool and
 * left the detail page the operator clicked next queued behind them for tens of
 * seconds. Counting requests is therefore the assertion that matters, and it is
 * invisible to every other kind of test.
 */

/** Per-row endpoints that must no longer be reachable from a list page. */
const PER_ROW_PATTERNS = [
  /\/api\/attributes\/[^/]+\/usage/,
  /\/api\/attributes\/[^/]+\/options(\?|$)/,
  /\/api\/attribute_groups\/[^/]+\/usage/,
  /\/api\/object_types\/[^/]+\/usage/,
  /\/api\/object_types\/[^/]+\/attached_groups/,
];

/**
 * Row links only: the same href prefix is also used by the "Nowy atrybut" CTA
 * (`/new`) and by the per-row "N wartości" link (`/values`).
 */
const ATTRIBUTE_ROW_LINK =
  'a[href^="/modeling/attributes/"]:not([href$="/new"]):not([href$="/values"])';

interface RequestTally {
  batched: string[];
  perRow: string[];
}

/**
 * `waitForLoadState('networkidle')` is unusable here: the admin holds a Mercure
 * SSE stream open, so the network never goes idle. And `waitForResponse` only
 * observes the future, so it deadlocks whenever the request already completed
 * while the page was rendering. Poll the tally instead — it was wired up before
 * navigation and therefore holds requests that fired either side of this call —
 * then give late stragglers a fixed window to appear.
 */
async function settle(page: import('@playwright/test').Page, tally: RequestTally): Promise<void> {
  await expect.poll(() => tally.batched.length, { timeout: 15_000 }).toBeGreaterThan(0);
  await page.waitForTimeout(1_500);
}

function tallyRequests(page: import('@playwright/test').Page): RequestTally {
  const tally: RequestTally = { batched: [], perRow: [] };

  page.on('request', (request) => {
    const url = request.url();
    if (url.includes('/api/modeling/usage/')) {
      tally.batched.push(url);
      return;
    }
    if (PER_ROW_PATTERNS.some((pattern) => pattern.test(url))) {
      tally.perRow.push(url);
    }
  });

  return tally;
}

test('attributes list reads usage counters in one batched request', async ({ page }) => {
  await loginAsAdmin(page);
  const tally = tallyRequests(page);

  await page.goto('/modeling/attributes');
  await expect(page.getByRole('heading', { name: /atrybuty|attributes/i })).toBeVisible();
  // Rows carry the counters; wait for one to render before counting requests.
  await expect(page.locator(ATTRIBUTE_ROW_LINK).first()).toBeVisible();
  await settle(page, tally);

  expect(tally.perRow, `per-row fan-out returned: ${tally.perRow.slice(0, 5).join(', ')}`).toEqual(
    [],
  );
  expect(tally.batched.length).toBeGreaterThan(0);
  // One request per resource; anything higher means the id list is churning
  // between renders and re-keying the query.
  expect(tally.batched.length).toBeLessThanOrEqual(2);
});

test('attribute groups list reads usage counters in one batched request', async ({ page }) => {
  await loginAsAdmin(page);
  const tally = tallyRequests(page);

  await page.goto('/modeling/attribute-groups');
  await expect(
    page.locator('a[href^="/modeling/attribute-groups/"]:not([href$="/new"])').first(),
  ).toBeVisible();
  await settle(page, tally);

  expect(tally.perRow, `per-row fan-out returned: ${tally.perRow.slice(0, 5).join(', ')}`).toEqual(
    [],
  );
  expect(tally.batched.length).toBeGreaterThan(0);
  expect(tally.batched.length).toBeLessThanOrEqual(2);
});

/**
 * The original report: opening an attribute from the list took seconds. The
 * detail page itself was always fast — it was queued behind the list's
 * fan-out, plus its own 15-request membership probe.
 */
test('opening an attribute from the list fires no per-row fan-out', async ({ page }) => {
  await loginAsAdmin(page);

  const listTally = tallyRequests(page);
  await page.goto('/modeling/attributes');
  const firstRow = page.locator(ATTRIBUTE_ROW_LINK).first();
  await expect(firstRow).toBeVisible();
  await settle(page, listTally);

  // Start a second tally once the list has settled, so it describes only what
  // the navigation into the detail page costs.
  const tally = tallyRequests(page);
  const membershipProbes: string[] = [];
  page.on('request', (request) => {
    if (/\/api\/attribute_groups\/[^/]+\/attributes/.test(request.url())) {
      membershipProbes.push(request.url());
    }
  });

  await firstRow.click();
  await expect(page).toHaveURL(/\/modeling\/attributes\/[0-9a-f-]{36}/);
  const openedId = new URL(page.url()).pathname.split('/').pop() ?? '';
  await page.waitForTimeout(2_500);

  expect(
    membershipProbes,
    `detail page still probes group membership per group: ${membershipProbes.length} requests`,
  ).toEqual([]);

  // A detail page legitimately reads the per-item endpoint for the ONE row it
  // shows (`<WhereUsedList>`); what it must never do is read it per group or
  // per sibling row. Assert scope rather than absence.
  const foreign = tally.perRow.filter((url) => !url.includes(openedId));
  expect(foreign, `detail page read usage for other rows: ${foreign.join(', ')}`).toEqual([]);
});
