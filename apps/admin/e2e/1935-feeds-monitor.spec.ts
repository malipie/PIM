import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-07 (#1935) — the global cross-feed monitor: sub-nav, the four 24h
 * KPI tiles from /api/feed-runs/kpi, the all-feeds run history with the feed
 * column and the status filter, and the shared drill-down. All mocked.
 */

const RUNS = {
  items: [
    {
      id: 'run-a',
      feed_id: 'f-a',
      feed_name: 'Google Shopping — Polska',
      feed_code: 'google_pl',
      trigger: 'schedule',
      status: 'done',
      health: 'success',
      item_count: 12408,
      skipped_count: 0,
      warning_count: 0,
      file_size_bytes: 18700000,
      duration_ms: 22000,
      error_message: null,
      started_at: '2026-07-02T04:00:00+00:00',
      completed_at: '2026-07-02T04:00:22+00:00',
    },
    {
      id: 'run-b',
      feed_id: 'f-b',
      feed_name: 'Ceneo — Elektronika',
      feed_code: 'ceneo_elektro',
      trigger: 'manual',
      status: 'error',
      health: 'error',
      item_count: 0,
      skipped_count: 0,
      warning_count: 0,
      file_size_bytes: null,
      duration_ms: 3000,
      error_message: 'missing required <price> slot',
      started_at: '2026-07-02T02:00:00+00:00',
      completed_at: '2026-07-02T02:00:03+00:00',
    },
  ],
  next_cursor: null,
};

test('XMLF-P5-07 — global monitor: KPI, cross-feed history, filter, drill-down', async ({
  page,
}) => {
  await loginAsAdmin(page);

  await page.route('**/api/feed-runs**', (route) => {
    const url = route.request().url();
    if (url.includes('/kpi')) {
      return route.fulfill({
        json: {
          regenerations_24h: 6,
          skipped_24h: 112,
          errors_24h: 1,
          last_error: null,
          items_syndicated: 20529,
          last_regenerated_at: '2026-07-02T04:00:00+00:00',
          feeds: { active: 2, paused: 0, error: 1 },
        },
      });
    }
    if (url.includes('status=error')) {
      return route.fulfill({ json: { items: [RUNS.items[1]], next_cursor: null } });
    }
    return route.fulfill({ json: RUNS });
  });
  await page.route('**/api/feeds/pull-stats', (route) =>
    route.fulfill({ json: { pulls_24h: 1284, spark: [] } }),
  );
  await page.route('**/api/feeds/*/runs/*/logs*', (route) =>
    route.fulfill({
      json: {
        items: [
          {
            id: 'log-1',
            level: 'error',
            object_sku: null,
            slot: 'price',
            message: 'missing required <price> slot',
            created_at: '2026-07-02T02:00:01+00:00',
          },
        ],
        next_cursor: null,
      },
    }),
  );

  await page.goto('/integrations/api-configurator/feeds/monitor');

  // Sub-nav with the monitor tab active (scoped: the layout has its own tablist).
  await expect(
    page.getByLabel(/Feeds sections|Sekcje feedów/).getByRole('tab', { name: /^Monitor$/ }),
  ).toHaveAttribute('aria-selected', 'true');

  // KPI tiles from the aggregates.
  await expect(page.getByText(/Regeneracje · 24h|Regenerations · 24h/)).toBeVisible();
  await expect(page.getByText(/20\s?529/)).toBeVisible();
  await expect(page.getByText('112', { exact: true })).toBeVisible();

  // Cross-feed history carries the feed identity column.
  await expect(page.getByText('Google Shopping — Polska')).toBeVisible();
  await expect(page.getByText('Ceneo — Elektronika')).toBeVisible();

  // Status filter narrows to errors only.
  await page.getByRole('button', { name: /błędy|errors/ }).click();
  await expect(page.getByText('Google Shopping — Polska')).not.toBeVisible();
  await expect(page.getByText('Ceneo — Elektronika')).toBeVisible();

  // Drill-down opens the shared sheet with the log line.
  await page
    .getByRole('button', { name: /ręcznie|manual/ })
    .first()
    .click();
  await expect(page.getByText(/— · price|— · price/)).toBeVisible();

  const axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );
});
