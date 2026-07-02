import { describe, expect, it } from 'vitest';

import type { FeedRow } from '../api/feeds';
import { canLeaveStep, draftFromFeed, emptyDraft, slugifyCode } from '../wizard/wizard-state';

/**
 * XMLF-P5-02 — pure wizard logic: the code auto-slug, step gating and the
 * edit-mode prefill mapping.
 */

describe('slugifyCode', () => {
  it('slugs a Polish display name into a mono code', () => {
    expect(slugifyCode('Google Shopping — Polska')).toBe('google_shopping_polska');
    expect(slugifyCode('Łóżka & Materace 2024')).toBe('lozka_materace_2024');
    expect(slugifyCode('  __x__  ')).toBe('x');
  });
});

describe('canLeaveStep', () => {
  it('gates step 0 on template and name', () => {
    const draft = emptyDraft();
    expect(canLeaveStep(0, draft)).toBe(false);
    draft.kind = 'ceneo';
    expect(canLeaveStep(0, draft)).toBe(false);
    draft.name = 'Ceneo PL';
    expect(canLeaveStep(0, draft)).toBe(true);
  });

  it('gates step 1 on a locale and lets later steps pass', () => {
    const draft = { ...emptyDraft(), kind: 'ceneo' as const, name: 'x', locale: '' };
    expect(canLeaveStep(1, draft)).toBe(false);
    draft.locale = 'pl';
    expect(canLeaveStep(1, draft)).toBe(true);
    expect(canLeaveStep(2, draft)).toBe(true);
  });
});

describe('draftFromFeed', () => {
  it('prefills every step-1/2 field from the API row', () => {
    const feed = {
      id: 'f-1',
      code: 'google_pl',
      name: 'Google — PL',
      template_kind: 'google_shopping',
      status: 'active',
      locale: 'pl',
      currency: 'PLN',
      channel_id: 'ch-1',
      publication_channel: null,
      schedule_cron: null,
      descriptor: {},
      field_mappings: [{ slot: 'g:id' }],
      filter: { field: 'status', operator: 'eq', value: 'active' },
      cached_item_count: null,
      cached_at: null,
      last_pulled_at: null,
      has_token: false,
    } as unknown as FeedRow;

    const draft = draftFromFeed(feed);
    expect(draft.id).toBe('f-1');
    expect(draft.kind).toBe('google_shopping');
    expect(draft.codeTouched).toBe(true);
    expect(draft.channelId).toBe('ch-1');
    expect(draft.mappings).toHaveLength(1);
    expect(draft.filterDsl).not.toBeNull();
  });
});
