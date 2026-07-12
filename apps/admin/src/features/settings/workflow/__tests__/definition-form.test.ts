import { describe, expect, it } from 'vitest';

import type { WorkflowDefinitionResource } from '@/lib/workflow/definitions-api';

import {
  draftFromResource,
  draftToPayload,
  emptyDraft,
  localViolations,
  violationsByField,
} from '../definition-form';

describe('draftToPayload', () => {
  it('drops empty optionals and trims strings', () => {
    const draft = emptyDraft();
    draft.name = '  Prosty  ';
    const published = draft.places[1];
    if (published === undefined) throw new Error('starter draft lost its places');
    published.labelPl = '';
    published.labelEn = '';
    const payload = draftToPayload(draft);

    expect(payload.name).toBe('Prosty');
    expect(payload.object_type_id).toBeUndefined();
    expect(payload.places[0]).toEqual({
      name: 'draft',
      label: { pl: 'Szkic', en: 'Draft' },
      color: '#71717a',
    });
    expect(payload.places[1]).toEqual({ name: 'published', color: '#16a34a' });
    expect(payload.transitions[0]).toEqual({
      name: 'publish_direct',
      from: ['draft'],
      to: 'published',
    });
  });

  it('serialises permission, comment_required and completeness gate', () => {
    const draft = emptyDraft();
    draft.name = 'Z bramka';
    const first = draft.transitions[0];
    if (first === undefined) throw new Error('starter draft lost its transition');
    first.permission = 'workflow.approve_reject';
    first.commentRequired = true;
    first.gatePct = '80';
    const transition = draftToPayload(draft).transitions[0];

    expect(transition).toEqual({
      name: 'publish_direct',
      from: ['draft'],
      to: 'published',
      permission: 'workflow.approve_reject',
      comment_required: true,
      completeness_gate: { min_completeness_pct: 80 },
    });
  });
});

describe('draftFromResource -> draftToPayload roundtrip', () => {
  it('keeps the shape stable', () => {
    const resource: WorkflowDefinitionResource = {
      id: '0198',
      name: 'Z tłumaczeniem',
      object_type_id: 'ot-1',
      places: [
        { name: 'draft', label: { pl: 'Szkic' }, color: '#111111' },
        { name: 'translation' },
        { name: 'published' },
      ],
      transitions: [
        { name: 'to_translation', from: 'draft', to: 'translation' },
        {
          name: 'approve_translation',
          from: ['translation'],
          to: 'published',
          permission: 'workflow.approve_reject',
          comment_required: true,
          completeness_gate: { min_completeness_pct: 90 },
        },
      ],
      reviewer: null,
      enabled: true,
      updated_at: '2026-07-11T00:00:00+00:00',
    };

    const payload = draftToPayload(draftFromResource(resource));
    expect(payload.object_type_id).toBe('ot-1');
    expect(payload.places.map((place) => place.name)).toEqual([
      'draft',
      'translation',
      'published',
    ]);
    // Scalar `from` normalises to a list on the way through the editor.
    expect(payload.transitions[0]?.from).toEqual(['draft']);
    expect(payload.transitions[1]).toMatchObject({
      permission: 'workflow.approve_reject',
      comment_required: true,
      completeness_gate: { min_completeness_pct: 90 },
    });
  });
});

describe('localViolations', () => {
  it('accepts the empty starter draft once named', () => {
    const draft = emptyDraft();
    draft.name = 'OK';
    expect(localViolations(draft)).toEqual([]);
  });

  it('flags bad names, duplicates, missing draft place and bad gate pct', () => {
    const draft = emptyDraft();
    draft.name = '';
    draft.places = [
      { name: 'Draft!', labelPl: '', labelEn: '', color: '' },
      { name: 'review', labelPl: '', labelEn: '', color: '' },
      { name: 'review', labelPl: '', labelEn: '', color: '' },
    ];
    draft.transitions = [
      { name: 'go', from: [], to: '', permission: '', commentRequired: false, gatePct: '150' },
    ];

    const fields = localViolations(draft).map((violation) => violation.field);
    expect(fields).toContain('name');
    expect(fields).toContain('places[0].name');
    expect(fields).toContain('places[2].name');
    expect(fields).toContain('places');
    expect(fields).toContain('transitions[0].from');
    expect(fields).toContain('transitions[0].to');
    expect(fields).toContain('transitions[0].completeness_gate');
  });
});

describe('violationsByField', () => {
  it('indexes by field with the last message winning', () => {
    expect(
      violationsByField([
        { field: 'places', message: 'first' },
        { field: 'places', message: 'second' },
        { field: 'transitions[0].name', message: 'dup' },
      ]),
    ).toEqual({ places: 'second', 'transitions[0].name': 'dup' });
  });
});
