import type { DefinitionPlace, DefinitionTransition } from '@/lib/workflow/definitions-api';

/**
 * WFL redesign (#2515) — the canonical shape of the built-in editorial
 * machine (ADR-0029), mirroring `config/packages/workflow.yaml` so a
 * saved definition drives the exact same machine.
 *
 * #3000 — its page ("Ustawienia przepływu") is gone; the shape stays
 * because it is the source of the "Jedna akceptacja" starter template
 * (#3004). Keeping the canonical transition NAMES matters: task
 * automation and notifications are wired to them, so a template that
 * renamed them would silently ship a flow without tasks.
 */

export const EDITORIAL_PLACES: readonly string[] = ['draft', 'review', 'published', 'archived'];

/** Transitions that land in `published` — the completeness gate applies here. */
const PUBLISH_TRANSITIONS = ['approve', 'publish'] as const;

export function editorialPlaces(): DefinitionPlace[] {
  return EDITORIAL_PLACES.map((name) => ({ name }));
}

/**
 * The canonical editorial transitions with the built-in permission map.
 * `gatePct` (0-100) attaches a completeness gate to the publish-bound
 * transitions; `null` clears it. `reject` always requires a comment.
 */
export function editorialTransitions(gatePct: number | null): DefinitionTransition[] {
  const gate =
    gatePct === null ? undefined : { completeness_gate: { min_completeness_pct: gatePct } };

  const withGate = (name: (typeof PUBLISH_TRANSITIONS)[number]) =>
    PUBLISH_TRANSITIONS.includes(name) ? gate : undefined;

  return [
    { name: 'submit_for_review', from: 'draft', to: 'review', permission: 'products.edit' },
    {
      name: 'publish',
      from: 'draft',
      to: 'published',
      permission: 'workflow.approve_reject',
      ...withGate('publish'),
    },
    {
      name: 'approve',
      from: 'review',
      to: 'published',
      permission: 'workflow.approve_reject',
      ...withGate('approve'),
    },
    {
      name: 'reject',
      from: 'review',
      to: 'draft',
      permission: 'workflow.approve_reject',
      comment_required: true,
    },
    {
      name: 'unpublish',
      from: 'published',
      to: 'draft',
      permission: 'workflow.transition.unpublish',
    },
    {
      name: 'archive',
      from: ['draft', 'published'],
      to: 'archived',
      permission: 'workflow.approve_reject',
    },
    { name: 'restore', from: 'archived', to: 'draft', permission: 'workflow.approve_reject' },
  ];
}

/**
 * Read the completeness gate back off a stored definition (the min pct
 * on the `approve` transition), or null when no gate is set.
 */
export function gateFromTransitions(transitions: DefinitionTransition[]): number | null {
  const approve = transitions.find((t) => t.name === 'approve');
  const pct = approve?.completeness_gate?.min_completeness_pct;
  return typeof pct === 'number' ? pct : null;
}
