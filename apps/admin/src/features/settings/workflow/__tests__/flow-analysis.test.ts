import { describe, expect, it } from 'vitest';
import type { DefinitionDraft, PlaceDraft, TransitionDraft } from '../definition-form';
import { analyseFlow, isFlowUsable, reachablePlaces } from '../flow-analysis';

function place(name: string, labelPl = ''): PlaceDraft {
  return { name, labelPl, labelEn: '', color: '#71717a', nameLocked: true };
}

function transition(
  name: string,
  from: string[],
  to: string,
  extra: Partial<TransitionDraft> = {},
): TransitionDraft {
  return {
    name,
    label: name,
    from,
    to,
    permission: 'products.edit',
    commentRequired: false,
    gatePct: '',
    nameLocked: true,
    ...extra,
  };
}

function draftOf(places: PlaceDraft[], transitions: TransitionDraft[]): DefinitionDraft {
  return { name: 'Test', objectTypeId: '', places, transitions, reviewer: '' };
}

const editorial = () =>
  draftOf(
    [place('draft', 'Szkic'), place('review', 'W przeglądzie'), place('published', 'Opublikowany')],
    [
      transition('submit_for_review', ['draft'], 'review'),
      transition('approve', ['review'], 'published'),
      transition('reject', ['review'], 'draft'),
    ],
  );

describe('reachablePlaces', () => {
  it('walks the graph from the initial place', () => {
    expect([...reachablePlaces(editorial().transitions)].sort()).toEqual([
      'draft',
      'published',
      'review',
    ]);
  });
});

describe('analyseFlow', () => {
  it('finds nothing wrong with the built-in editorial flow', () => {
    const findings = analyseFlow(editorial());
    expect(findings).toEqual([]);
    expect(isFlowUsable(findings)).toBe(true);
  });

  it('reports a stage cut off from the start', () => {
    const draft = editorial();
    draft.places.push(place('parked', 'Wstrzymany'));

    const findings = analyseFlow(draft);
    expect(findings).toContainEqual({
      code: 'unreachable_places',
      level: 'error',
      items: ['Wstrzymany'],
    });
    expect(isFlowUsable(findings)).toBe(false);
  });

  it('reports a flow that can never publish', () => {
    const draft = draftOf(
      [place('draft', 'Szkic'), place('published', 'Opublikowany')],
      [transition('park', ['published'], 'draft')],
    );

    expect(analyseFlow(draft).map((finding) => finding.code)).toContain('no_publish_path');
  });

  it('requires the initial place and stops there', () => {
    const draft = draftOf([place('review')], []);
    expect(analyseFlow(draft)).toEqual([{ code: 'no_start', level: 'error', items: [] }]);
  });

  it('warns — but does not block — on an ungated action', () => {
    const draft = editorial();
    const first = draft.transitions[0];
    if (first === undefined) throw new Error('fixture lost its transitions');
    first.permission = '';

    const findings = analyseFlow(draft);
    expect(findings).toContainEqual({
      code: 'missing_permission',
      level: 'warning',
      items: ['submit_for_review'],
    });
    expect(isFlowUsable(findings)).toBe(true);
  });

  it('warns about a custom step, because no task comes out of it', () => {
    const draft = editorial();
    draft.transitions.push(
      transition('przekaz_do_publikacji', ['published'], 'draft', {
        label: 'Przekaż do publikacji',
      }),
    );

    expect(analyseFlow(draft)).toContainEqual({
      code: 'custom_transitions',
      level: 'warning',
      items: ['Przekaż do publikacji'],
    });
  });

  it('flags a half-filled action row', () => {
    const draft = editorial();
    draft.transitions.push(transition('', [], '', { label: '' }));

    expect(analyseFlow(draft).map((finding) => finding.code)).toContain('incomplete_transition');
  });
});
