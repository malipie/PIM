import type { FilterDsl } from '@/lib/filters/filter-dsl';

import type { FeedRow, FeedTemplateKind } from '../api/feeds';
import type { SlotMapping } from '../api/mapping';

/**
 * XMLF-P5-02 — the wizard's draft state (design feed-wizard.jsx FeedWizard):
 * steps 1-2 own template/identity/scope/filter; mapping (P5-03) and
 * delivery/preview (P5-04) extend the same draft in their tickets.
 */
export interface FeedDraft {
  id: string | null;
  kind: FeedTemplateKind | null;
  name: string;
  code: string;
  codeTouched: boolean;
  descriptor: Record<string, unknown>;
  locale: string;
  currency: string | null;
  channelId: string | null;
  mappings: SlotMapping[];
  filterDsl: FilterDsl | null;
  skipPolicy: 'skip_invalid' | 'include_with_warning';
}

export const WIZARD_STEP_IDS = ['template', 'scope', 'mapping', 'delivery', 'preview'] as const;
export type WizardStepId = (typeof WIZARD_STEP_IDS)[number];

export function emptyDraft(): FeedDraft {
  return {
    id: null,
    kind: null,
    name: '',
    code: '',
    codeTouched: false,
    descriptor: {},
    locale: 'pl',
    currency: null,
    channelId: null,
    mappings: [],
    filterDsl: null,
    skipPolicy: 'skip_invalid',
  };
}

/** Edit mode — prefill from GET /api/feeds/:id. */
export function draftFromFeed(feed: FeedRow): FeedDraft {
  return {
    id: feed.id,
    kind: feed.template_kind,
    name: feed.name,
    code: feed.code,
    codeTouched: true,
    descriptor: feed.descriptor,
    locale: feed.locale ?? 'pl',
    currency: feed.currency,
    channelId: feed.channel_id,
    mappings: feed.field_mappings as unknown as SlotMapping[],
    filterDsl: (feed.filter as FilterDsl | null) ?? null,
    skipPolicy: feed.validation_policy ?? 'skip_invalid',
  };
}

/** Auto-slug for the code field: `Google Shopping — PL` → `google_shopping_pl`. */
export function slugifyCode(name: string): string {
  return name
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/ł/g, 'l')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

/** Step gating (design canNext): step 0 needs a template and a name. */
export function canLeaveStep(step: number, draft: FeedDraft): boolean {
  if (step === 0) {
    return draft.kind !== null && draft.name.trim() !== '';
  }
  if (step === 1) {
    return draft.locale.trim() !== '';
  }
  return true;
}

/** POST /api/feeds payload for a fresh draft. */
export function createPayload(draft: FeedDraft, objectTypeId: string): Record<string, unknown> {
  return {
    template_kind: draft.kind,
    name: draft.name.trim(),
    code: draft.code.trim(),
    object_type_id: objectTypeId,
    locale: draft.locale,
    currency: draft.currency,
    channel_id: draft.channelId,
    filter: draft.filterDsl,
  };
}

/** PATCH /api/feeds/:id payload — scope + filter + identity rename. */
export function patchPayload(draft: FeedDraft): Record<string, unknown> {
  return {
    name: draft.name.trim(),
    locale: draft.locale,
    currency: draft.currency,
    channel_id: draft.channelId,
    filter: draft.filterDsl,
    validation_policy: draft.skipPolicy,
  };
}
