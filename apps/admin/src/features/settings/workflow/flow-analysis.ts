import type { DefinitionDraft, PlaceDraft, TransitionDraft } from './definition-form';
import { isCanonicalTransition } from './flow-vocabulary';

/**
 * #3003 — readiness of the flow being edited, computed live. Mirrors the
 * cheap rules of the backend `WorkflowDefinitionValidator` (reachability,
 * initial place) so the operator sees a problem while typing instead of
 * as a 422 after Save, and adds the two things the backend cannot judge:
 * an action nobody is gated on, and a custom step that produces no task.
 *
 * Errors block a working flow; warnings are legitimate choices we simply
 * refuse to leave implicit. Neither blocks saving — the backend stays the
 * authority on what is valid.
 */

export type FindingLevel = 'error' | 'warning';

export interface FlowFinding {
  code:
    | 'no_start'
    | 'unreachable_places'
    | 'no_publish_path'
    | 'missing_permission'
    | 'custom_transitions'
    | 'incomplete_transition';
  level: FindingLevel;
  /** Names/labels the message interpolates, already human-readable. */
  items: string[];
}

const INITIAL_PLACE = 'draft';
const PUBLISHED_PLACE = 'published';

/** Places reachable from `draft`, following transitions (BFS). */
export function reachablePlaces(transitions: TransitionDraft[]): Set<string> {
  const edges = new Map<string, string[]>();
  for (const transition of transitions) {
    if (transition.to === '') continue;
    for (const from of transition.from) {
      edges.set(from, [...(edges.get(from) ?? []), transition.to]);
    }
  }

  const seen = new Set<string>([INITIAL_PLACE]);
  const queue: string[] = [INITIAL_PLACE];
  while (queue.length > 0) {
    const current = queue.shift();
    if (current === undefined) break;
    for (const next of edges.get(current) ?? []) {
      if (seen.has(next)) continue;
      seen.add(next);
      queue.push(next);
    }
  }
  return seen;
}

function labelOf(place: PlaceDraft): string {
  return place.labelPl.trim() === '' ? place.name : place.labelPl.trim();
}

export function analyseFlow(draft: DefinitionDraft): FlowFinding[] {
  const findings: FlowFinding[] = [];
  const places = draft.places.filter((place) => place.name.trim() !== '');

  if (!places.some((place) => place.name === INITIAL_PLACE)) {
    findings.push({ code: 'no_start', level: 'error', items: [] });
    return findings;
  }

  const reachable = reachablePlaces(draft.transitions);
  const stranded = places.filter((place) => !reachable.has(place.name)).map(labelOf);
  if (stranded.length > 0) {
    findings.push({ code: 'unreachable_places', level: 'error', items: stranded });
  }

  const hasPublished = places.some((place) => place.name === PUBLISHED_PLACE);
  if (hasPublished && !reachable.has(PUBLISHED_PLACE)) {
    findings.push({ code: 'no_publish_path', level: 'error', items: [] });
  }

  const incomplete = draft.transitions
    .filter(
      (transition) =>
        transition.label.trim() === '' || transition.from.length === 0 || transition.to === '',
    )
    .map((transition, index) =>
      transition.label.trim() === '' ? `#${index + 1}` : transition.label.trim(),
    );
  if (incomplete.length > 0) {
    findings.push({ code: 'incomplete_transition', level: 'error', items: incomplete });
  }

  const ungated = draft.transitions
    .filter((transition) => transition.permission === '' && transition.label.trim() !== '')
    .map((transition) => transition.label.trim());
  if (ungated.length > 0) {
    findings.push({ code: 'missing_permission', level: 'warning', items: ungated });
  }

  const custom = draft.transitions
    .filter((transition) => transition.name !== '' && !isCanonicalTransition(transition.name))
    .map((transition) => (transition.label.trim() === '' ? transition.name : transition.label));
  if (custom.length > 0) {
    findings.push({ code: 'custom_transitions', level: 'warning', items: custom });
  }

  return findings;
}

/** True when nothing is broken — warnings are allowed to remain. */
export function isFlowUsable(findings: FlowFinding[]): boolean {
  return !findings.some((finding) => finding.level === 'error');
}
