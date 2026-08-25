import type { DefinitionDraft, PlaceDraft, TransitionDraft } from './definition-form';
import { editorialPlaces, editorialTransitions } from './editorial-shape';
import { humanizeName, PLACE_BLOCKS } from './flow-vocabulary';

/**
 * #3004 — starter flows, so "Nowy przepływ" opens with a question an
 * operator can answer instead of an empty form with a `publish_direct`
 * row in it.
 *
 * Every template uses CANONICAL transition names — task automation and
 * notifications are wired to them (`EditorialTransitionEventRecorder`
 * falls through to `default => null` for anything else), so a template
 * with prettier names would hand out flows whose review step creates no
 * task. The one-approval template is built from `editorial-shape`, the
 * same source the built-in machine mirrors, rather than a second copy.
 */

export type TemplateId = 'no_approval' | 'one_approval' | 'approval_then_publish';

export interface FlowTemplate {
  id: TemplateId;
  /** Places in order, for the chooser's little chain preview. */
  chain: string[];
  build: () => DefinitionDraft;
}

function placeDraft(name: string): PlaceDraft {
  const block = PLACE_BLOCKS.find((candidate) => candidate.name === name);
  if (block !== undefined) return { ...block, nameLocked: true };
  return { name, labelPl: humanizeName(name), labelEn: '', color: '#71717a', nameLocked: true };
}

function transitionDraft(
  name: string,
  from: string[],
  to: string,
  extra: Partial<TransitionDraft> = {},
): TransitionDraft {
  return {
    name,
    label: humanizeName(name),
    from,
    to,
    permission: '',
    commentRequired: false,
    gatePct: '',
    nameLocked: true,
    ...extra,
  };
}

function emptyShell(places: PlaceDraft[], transitions: TransitionDraft[]): DefinitionDraft {
  return { name: '', objectTypeId: '', places, transitions, reviewer: '' };
}

/** Editor draft built from the canonical editorial shape (single source). */
function oneApprovalDraft(): DefinitionDraft {
  const places = editorialPlaces().map((place) => placeDraft(place.name));
  const transitions = editorialTransitions(80).map((transition) =>
    transitionDraft(
      transition.name,
      Array.isArray(transition.from) ? transition.from : [transition.from],
      transition.to,
      {
        permission: transition.permission ?? '',
        commentRequired: transition.comment_required === true,
        gatePct:
          transition.completeness_gate === undefined
            ? ''
            : String(transition.completeness_gate.min_completeness_pct),
      },
    ),
  );
  return emptyShell(places, transitions);
}

export const FLOW_TEMPLATES: readonly FlowTemplate[] = [
  {
    id: 'no_approval',
    chain: ['draft', 'published'],
    build: () =>
      emptyShell(
        [placeDraft('draft'), placeDraft('published'), placeDraft('archived')],
        [
          transitionDraft('publish', ['draft'], 'published', { permission: 'products.edit' }),
          transitionDraft('unpublish', ['published'], 'draft', {
            permission: 'workflow.transition.unpublish',
          }),
          transitionDraft('archive', ['draft', 'published'], 'archived', {
            permission: 'products.edit',
          }),
          transitionDraft('restore', ['archived'], 'draft', { permission: 'products.edit' }),
        ],
      ),
  },
  {
    id: 'one_approval',
    chain: ['draft', 'review', 'published'],
    build: oneApprovalDraft,
  },
  {
    id: 'approval_then_publish',
    chain: ['draft', 'review', 'approved', 'published'],
    build: () => {
      const draft = oneApprovalDraft();
      // Approval stops at `approved`; publishing to the channels becomes a
      // separate step for whoever runs exports and integrations. That step
      // is custom by definition, so the editor warns that it carries no
      // task — the trade-off is stated in the chooser, not discovered later.
      draft.places = [
        placeDraft('draft'),
        placeDraft('review'),
        placeDraft('approved'),
        placeDraft('published'),
        placeDraft('archived'),
      ];
      draft.transitions = draft.transitions.map((transition) =>
        transition.name === 'approve' ? { ...transition, to: 'approved', gatePct: '' } : transition,
      );
      draft.transitions.push(
        transitionDraft('publish_approved', ['approved'], 'published', {
          label: 'Przekaż do publikacji',
          permission: 'publications.publish_unpublish',
          gatePct: '80',
        }),
      );
      return draft;
    },
  },
];

export function templateById(id: TemplateId): FlowTemplate | undefined {
  return FLOW_TEMPLATES.find((template) => template.id === id);
}
