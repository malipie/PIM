import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from './helpers/auth';

/**
 * XMLF-P5-05 (#1933) — the feed detail: header + KPI from the feed row, the
 * run history (P4-03 read API) with the status filter, and the drill-down
 * sheet with the per-product FeedRunLog. The live Mercure pipeline is
 * covered by the unmocked smoke (EventSource does not mock cleanly); this
 * spec pins the REST-driven surface deterministically.
 */

const FEED = {
  id: '019f0000-0000-7000-8000-00000000f001',
  code: 'google_pl',
  name: 'Google Shopping — Polska',
  template_kind: 'google_shopping',
  status: 'active',
  locale: 'pl',
  currency: 'PLN',
  channel_id: null,
  publication_channel: null,
  schedule_cron: '0 3 * * *',
  descriptor: { item: { slots: [{ slot: 'g:id' }, { slot: 'g:title' }] } },
  field_mappings: [{ slot: 'g:id' }],
  cached_item_count: 12408,
  cached_at: '2026-07-01T04:00:00+00:00',
  last_pulled_at: '2026-07-01T21:00:00+00:00',
  has_token: true,
};

const RUNS = {
  items: [
    {
      id: 'run-1',
      feed_id: FEED.id,
      trigger: 'manual',
      status: 'done',
      health: 'warning',
      item_count: 12408,
      skipped_count: 34,
      warning_count: 34,
      file_size_bytes: 18700000,
      duration_ms: 22000,
      error_message: null,
      started_at: '2026-07-01T04:00:00+00:00',
      completed_at: '2026-07-01T04:00:22+00:00',
    },
  ],
  next_cursor: null,
};

const LOGS = {
  items: [
    {
      id: 'log-1',
      level: 'warning',
      object_sku: 'KL-PT-80240',
      slot: 'g:gtin',
      message: 'missing required g:gtin — skipped',
      created_at: '2026-07-01T04:00:10+00:00',
    },
  ],
  next_cursor: null,
};

test('XMLF-P5-05 — detail: header, KPI, history, drill-down log', async ({ page }) => {
  await loginAsAdmin(page);

  await page.route('**/api/feeds/**', (route) => {
    const url = route.request().url();
    if (url.includes('/runs/') && url.includes('/logs')) {
      return route.fulfill({ json: LOGS });
    }
    if (url.endsWith('/runs') || url.includes('/runs?')) {
      return route.fulfill({ json: RUNS });
    }
    return route.fulfill({ json: FEED });
  });
  await page.route('**/.well-known/mercure*', (route) => route.abort());
  await page.route('**/api/mercure/authorization', (route) =>
    route.fulfill({ status: 204, body: '' }),
  );

  await page.goto(`/integrations/api-configurator/feeds/${FEED.id}`);

  // Header + KPI from the feed row.
  await expect(page.getByRole('heading', { name: /Google Shopping — Polska/ })).toBeVisible();
  await expect(page.getByText(/12\s?408/).first()).toBeVisible();
  await expect(page.getByText('0 3 * * *')).toBeVisible();
  await expect(page.getByText('1/2')).toBeVisible();

  // Run history renders with the warning health pill.
  await expect(page.getByText(/ostrzeżenie|warning/).first()).toBeVisible();
  await expect(page.getByText('⤼ 34')).toBeVisible();

  // Drill-down shows the per-product log line.
  await page
    .getByRole('button', { name: /ręcznie|manual/ })
    .first()
    .click();
  await expect(page.getByText('KL-PT-80240 · g:gtin')).toBeVisible();
  await expect(page.getByText(/missing required g:gtin/)).toBeVisible();

  const axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual(
    [],
  );
});
