import { describe, expect, it } from 'vitest';

import { computeCoverage, type FeedRow, filterFeeds } from '../api/feeds';

/**
 * XMLF-P5-01 — pure hub logic: mapping coverage (both descriptor shapes) and
 * the toolbar's combined search + status filtering.
 */

function feed(overrides: Partial<FeedRow>): FeedRow {
  return {
    id: 'f1',
    code: 'google_pl',
    name: 'Google Shopping — Polska',
    template_kind: 'google_shopping',
    status: 'active',
    locale: 'pl',
    currency: 'PLN',
    channel_id: null,
    publication_channel: null,
    schedule_cron: null,
    descriptor: {},
    field_mappings: [],
    cached_item_count: null,
    cached_at: null,
    last_pulled_at: null,
    has_token: false,
    ...overrides,
  };
}

describe('computeCoverage', () => {
  it('counts flat custom descriptors', () => {
    const coverage = computeCoverage(
      {
        item: {
          slots: [{ slot: 'sku' }, { slot: 'name' }, { slot: 'price' }],
        },
      },
      [{ slot: 'sku' }, { slot: 'price' }, { slot: 'price' }],
    );
    expect(coverage).toEqual({ mapped: 2, total: 3 });
  });

  it('counts channel-nested marketplace descriptors (Google RSS)', () => {
    const coverage = computeCoverage(
      {
        channel: {
          item: { slots: [{ slot: 'g:id' }, { slot: 'g:title' }] },
        },
      },
      [{ slot: 'g:id' }],
    );
    expect(coverage).toEqual({ mapped: 1, total: 2 });
  });

  it('ignores mappings pointing at unknown slots', () => {
    const coverage = computeCoverage({ item: { slots: [{ slot: 'sku' }] } }, [{ slot: 'ghost' }]);
    expect(coverage).toEqual({ mapped: 0, total: 1 });
  });
});

describe('filterFeeds', () => {
  const feeds = [
    feed({ id: '1', code: 'google_pl', name: 'Google Shopping — Polska', status: 'active' }),
    feed({ id: '2', code: 'ceneo_elektro', name: 'Ceneo — Elektronika', status: 'paused' }),
    feed({ id: '3', code: 'b2b_stalko', name: 'Feed B2B — Stalko', status: 'error' }),
  ];

  it('searches name and code case-insensitively', () => {
    expect(filterFeeds(feeds, 'CENEO', 'all').map((f) => f.id)).toEqual(['2']);
    expect(filterFeeds(feeds, 'stalko', 'all').map((f) => f.id)).toEqual(['3']);
  });

  it('narrows by status and combines with search', () => {
    expect(filterFeeds(feeds, '', 'paused').map((f) => f.id)).toEqual(['2']);
    expect(filterFeeds(feeds, 'google', 'error')).toEqual([]);
  });
});
