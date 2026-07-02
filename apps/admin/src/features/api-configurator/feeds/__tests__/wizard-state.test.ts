import { describe, expect, it } from 'vitest';

import type { FeedRow } from '../api/feeds';
import {
  canLeaveStep,
  createPayload,
  draftFromFeed,
  emptyDraft,
  patchPayload,
  slugifyCode,
  stepsFor,
} from '../wizard/wizard-state';

/**
 * XMLF-P5-02 (+P5-06 id-based steps) — pure wizard logic: the code auto-slug,
 * the kind-dependent step list, step gating and the edit-mode prefill mapping.
 */

describe('slugifyCode', () => {
  it('slugs a Polish display name into a mono code', () => {
    expect(slugifyCode('Google Shopping — Polska')).toBe('google_shopping_polska');
    expect(slugifyCode('Łóżka & Materace 2024')).toBe('lozka_materace_2024');
    expect(slugifyCode('  __x__  ')).toBe('x');
  });
});

describe('stepsFor', () => {
  it('inserts the structure step for custom feeds only', () => {
    expect(stepsFor('custom')).toEqual([
      'template',
      'structure',
      'scope',
      'mapping',
      'delivery',
      'preview',
    ]);
    expect(stepsFor('google_shopping')).toEqual([
      'template',
      'scope',
      'mapping',
      'delivery',
      'preview',
    ]);
    expect(stepsFor(null)).not.toContain('structure');
  });
});

describe('canLeaveStep', () => {
  it('gates the template step on template and name', () => {
    const draft = emptyDraft();
    expect(canLeaveStep('template', draft)).toBe(false);
    draft.kind = 'ceneo';
    expect(canLeaveStep('template', draft)).toBe(false);
    draft.name = 'Ceneo PL';
    expect(canLeaveStep('template', draft)).toBe(true);
  });

  it('gates scope on a locale and lets later steps pass', () => {
    const draft = { ...emptyDraft(), kind: 'ceneo' as const, name: 'x', locale: '' };
    expect(canLeaveStep('scope', draft)).toBe(false);
    draft.locale = 'pl';
    expect(canLeaveStep('scope', draft)).toBe(true);
    expect(canLeaveStep('mapping', draft)).toBe(true);
  });

  it('gates structure on the inline validation verdict', () => {
    const draft = { ...emptyDraft(), kind: 'custom' as const, name: 'x' };
    expect(canLeaveStep('structure', draft, false)).toBe(false);
    expect(canLeaveStep('structure', draft, true)).toBe(true);
  });
});

describe('custom descriptor in payloads', () => {
  it('sends descriptor + mapped-only field_mappings on create for custom', () => {
    const draft = {
      ...emptyDraft(),
      kind: 'custom' as const,
      name: 'B2B',
      code: 'b2b',
      descriptor: { root: { element: 'products' }, item: { element: 'product', slots: [] } },
      mappings: [
        { slot: 'sku', source: { kind: 'attribute', ref: 'sku' } },
        { slot: 'name', source: null },
      ] as never[],
    };
    const payload = createPayload(draft, 'type-1');
    expect(payload.descriptor).toEqual(draft.descriptor);
    expect(payload.field_mappings).toHaveLength(1);
    expect(patchPayload(draft).descriptor).toEqual(draft.descriptor);
  });

  it('omits descriptor for predefined templates', () => {
    const draft = { ...emptyDraft(), kind: 'ceneo' as const, name: 'x', code: 'x' };
    expect(createPayload(draft, 'type-1')).not.toHaveProperty('descriptor');
    expect(patchPayload(draft)).not.toHaveProperty('descriptor');
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

describe('sampleValuesFromXml', () => {
  it('resolves element and namespaced values from the first item', async () => {
    const { sampleValuesFromXml } = await import('../api/mapping');
    const xml = `<?xml version="1.0"?><rss xmlns:g="http://base.google.com/ns/1.0"><channel><item><g:id>KL-1</g:id><g:title>Wkręt</g:title></item></channel></rss>`;
    const slots = [
      {
        target: 'g:id',
        element: 'g:id',
        node: 'element',
        required: true,
        required_one_of: [],
        format: 'text',
        max_length: null,
        enums: [],
        mapping: null,
        mapped: true,
        type_warning: null,
      },
      {
        target: 'g:title',
        element: 'g:title',
        node: 'element',
        required: true,
        required_one_of: [],
        format: 'text',
        max_length: 150,
        enums: [],
        mapping: null,
        mapped: true,
        type_warning: null,
      },
      {
        target: 'g:brand',
        element: 'g:brand',
        node: 'element',
        required: false,
        required_one_of: [],
        format: 'text',
        max_length: null,
        enums: [],
        mapping: null,
        mapped: false,
        type_warning: null,
      },
    ] as never[];
    const values = sampleValuesFromXml(xml, slots);
    expect(values.get('g:id')).toBe('KL-1');
    expect(values.get('g:title')).toBe('Wkręt');
    expect(values.has('g:brand')).toBe(false);
  });

  it('returns empty on malformed xml', async () => {
    const { sampleValuesFromXml } = await import('../api/mapping');
    expect(sampleValuesFromXml('<broken', []).size).toBe(0);
  });
});

describe('sanitizeMappings', () => {
  it('drops attribute refs the tenant does not have, keeps the rest', async () => {
    const { sanitizeMappings } = await import('../api/mapping');
    const cleaned = sanitizeMappings(
      [
        { slot: 'g:id', source: { kind: 'attribute', ref: 'sku' } },
        { slot: 'g:availability', source: { kind: 'attribute', ref: 'stock_qty' } },
        { slot: 'g:condition', source: { kind: 'static', value: 'new' } },
      ],
      [{ code: 'sku', label: 'SKU', type: 'text' }],
    );
    expect(cleaned[0]?.source).not.toBeNull();
    expect(cleaned[1]?.source).toBeNull();
    expect(cleaned[2]?.source?.kind).toBe('static');
  });
});
