import type {
  DefinitionPayload,
  DefinitionReviewer,
  DefinitionViolation,
  WorkflowDefinitionResource,
} from '@/lib/workflow/definitions-api';

/**
 * WFL-P5-03 (#2433) — pure editor-state <-> API-payload mapping for the
 * definition builder. Kept free of React so every rule is unit-testable:
 * the component owns rendering, this module owns the shape.
 */

export interface PlaceDraft {
  name: string;
  labelPl: string;
  labelEn: string;
  color: string;
}

export interface TransitionDraft {
  name: string;
  from: string[];
  to: string;
  permission: string;
  commentRequired: boolean;
  /** Empty string = no completeness gate. */
  gatePct: string;
}

export interface DefinitionDraft {
  name: string;
  objectTypeId: string;
  places: PlaceDraft[];
  transitions: TransitionDraft[];
  /**
   * #3001 — task recipient, as one picker value: `role:<code>` or
   * `user:<uuid>`. Empty string = no configured reviewer, i.e. the
   * built-in `approver` role handles review tasks.
   */
  reviewer: string;
}

const REVIEWER_ROLE_PREFIX = 'role:';
const REVIEWER_USER_PREFIX = 'user:';

/** API reviewer envelope -> picker value. */
export function reviewerToValue(reviewer: DefinitionReviewer): string {
  if (reviewer === null) return '';
  if ('role_code' in reviewer) return REVIEWER_ROLE_PREFIX + reviewer.role_code;
  return REVIEWER_USER_PREFIX + reviewer.user_id;
}

/** Picker value -> API reviewer envelope (XOR role/user, null when unset). */
export function valueToReviewer(value: string): DefinitionReviewer {
  if (value.startsWith(REVIEWER_ROLE_PREFIX)) {
    return { role_code: value.slice(REVIEWER_ROLE_PREFIX.length) };
  }
  if (value.startsWith(REVIEWER_USER_PREFIX)) {
    return { user_id: value.slice(REVIEWER_USER_PREFIX.length) };
  }
  return null;
}

/** Fresh draft mirroring the static machine's minimum viable chain. */
export function emptyDraft(): DefinitionDraft {
  return {
    name: '',
    objectTypeId: '',
    places: [
      { name: 'draft', labelPl: 'Szkic', labelEn: 'Draft', color: '#71717a' },
      { name: 'published', labelPl: 'Opublikowany', labelEn: 'Published', color: '#16a34a' },
    ],
    transitions: [
      {
        name: 'publish_direct',
        from: ['draft'],
        to: 'published',
        permission: '',
        commentRequired: false,
        gatePct: '',
      },
    ],
    reviewer: '',
  };
}

export function draftFromResource(resource: WorkflowDefinitionResource): DefinitionDraft {
  return {
    name: resource.name,
    objectTypeId: resource.object_type_id ?? '',
    places: resource.places.map((place) => ({
      name: place.name,
      labelPl: place.label?.pl ?? '',
      labelEn: place.label?.en ?? '',
      color: place.color ?? '#71717a',
    })),
    transitions: resource.transitions.map((transition) => ({
      name: transition.name,
      from: Array.isArray(transition.from) ? transition.from : [transition.from],
      to: transition.to,
      permission: transition.permission ?? '',
      commentRequired: transition.comment_required === true,
      gatePct:
        transition.completeness_gate === undefined
          ? ''
          : String(transition.completeness_gate.min_completeness_pct),
    })),
    // #3001 — read the stored reviewer back. Without this the editor would
    // send `reviewer: null` on every save and silently wipe a routing the
    // operator configured, because the payload always carries the field.
    reviewer: reviewerToValue(resource.reviewer),
  };
}

/** Serialise the draft into the CRUD payload; empty optionals are dropped. */
export function draftToPayload(draft: DefinitionDraft): DefinitionPayload {
  return {
    name: draft.name.trim(),
    ...(draft.objectTypeId !== '' ? { object_type_id: draft.objectTypeId } : {}),
    places: draft.places.map((place) => ({
      name: place.name.trim(),
      ...(place.labelPl.trim() !== '' || place.labelEn.trim() !== ''
        ? {
            label: {
              ...(place.labelPl.trim() !== '' ? { pl: place.labelPl.trim() } : {}),
              ...(place.labelEn.trim() !== '' ? { en: place.labelEn.trim() } : {}),
            },
          }
        : {}),
      ...(place.color !== '' ? { color: place.color } : {}),
    })),
    transitions: draft.transitions.map((transition) => ({
      name: transition.name.trim(),
      from: transition.from,
      to: transition.to,
      ...(transition.permission !== '' ? { permission: transition.permission } : {}),
      ...(transition.commentRequired ? { comment_required: true } : {}),
      ...(transition.gatePct !== ''
        ? { completeness_gate: { min_completeness_pct: Number(transition.gatePct) } }
        : {}),
    })),
    reviewer: valueToReviewer(draft.reviewer),
  };
}

/**
 * Index API violations by their field path (`places[1].name`,
 * `transitions[0].permission`, bare `places`) for inline rendering.
 * Later duplicates win — the API reports in document order, so the last
 * message is the most specific one the user acted on.
 */
export function violationsByField(violations: DefinitionViolation[]): Record<string, string> {
  const map: Record<string, string> = {};
  for (const violation of violations) {
    map[violation.field] = violation.message;
  }
  return map;
}

/**
 * Client-side pre-checks mirroring the backend validator's cheap rules so
 * the obvious mistakes surface before a request round-trip. The backend
 * stays authoritative (reachability, permission existence, live places).
 */
export function localViolations(draft: DefinitionDraft): DefinitionViolation[] {
  const violations: DefinitionViolation[] = [];
  const snake = /^[a-z][a-z0-9_]{0,31}$/;

  if (draft.name.trim() === '') {
    violations.push({ field: 'name', message: 'required' });
  }

  const seenPlaces = new Set<string>();
  draft.places.forEach((place, index) => {
    if (!snake.test(place.name.trim())) {
      violations.push({ field: `places[${index}].name`, message: 'snake_case' });
      return;
    }
    if (seenPlaces.has(place.name.trim())) {
      violations.push({ field: `places[${index}].name`, message: 'duplicate' });
      return;
    }
    seenPlaces.add(place.name.trim());
  });
  if (!seenPlaces.has('draft')) {
    violations.push({ field: 'places', message: 'missing_draft' });
  }

  const seenTransitions = new Set<string>();
  draft.transitions.forEach((transition, index) => {
    if (!snake.test(transition.name.trim())) {
      violations.push({ field: `transitions[${index}].name`, message: 'snake_case' });
    } else if (seenTransitions.has(transition.name.trim())) {
      violations.push({ field: `transitions[${index}].name`, message: 'duplicate' });
    } else {
      seenTransitions.add(transition.name.trim());
    }
    if (transition.from.length === 0) {
      violations.push({ field: `transitions[${index}].from`, message: 'required' });
    }
    if (transition.to === '') {
      violations.push({ field: `transitions[${index}].to`, message: 'required' });
    }
    if (transition.gatePct !== '') {
      const pct = Number(transition.gatePct);
      if (!Number.isInteger(pct) || pct < 0 || pct > 100) {
        violations.push({ field: `transitions[${index}].completeness_gate`, message: 'pct_range' });
      }
    }
  });

  return violations;
}
