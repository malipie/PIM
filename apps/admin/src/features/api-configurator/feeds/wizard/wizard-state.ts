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
  cron: string | null;
  gzip: boolean;
  authType: 'none' | 'basic';
  authUsername: string;
  /** Write-only — sent on PATCH when non-empty, never read back. */
  authPassword: string;
}

export const WIZARD_STEP_IDS = [
  'template',
  'structure',
  'scope',
  'mapping',
  'delivery',
  'preview',
] as const;
export type WizardStepId = (typeof WIZARD_STEP_IDS)[number];

/**
 * XMLF-P5-06 — the step list is a function of the template kind: only a
 * custom feed gets the structure editor (predefs have a fixed descriptor).
 * Steps are addressed by id everywhere; indexes are derived per draft.
 */
export function stepsFor(kind: FeedTemplateKind | null): readonly WizardStepId[] {
  return kind === 'custom' ? WIZARD_STEP_IDS : WIZARD_STEP_IDS.filter((s) => s !== 'structure');
}

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
    cron: null,
    gzip: true,
    authType: 'none',
    authUsername: '',
    authPassword: '',
  };
}

/** Edit mode — prefill from GET /api/feeds/:id. */
/**
 * #2740 — the wire rows are typed `Record<string, unknown>`; keep only the ones
 * that actually carry a `slot` instead of asserting the whole array is already
 * `SlotMapping[]`. A backend shape change then drops the unusable rows rather
 * than handing the mapper undefined slots.
 */
export function toSlotMappings(rows: Array<Record<string, unknown>>): SlotMapping[] {
  const out: SlotMapping[] = [];
  for (const row of rows) {
    if (typeof row.slot !== 'string') continue;
    out.push({
      slot: row.slot,
      source: (row.source ?? null) as SlotMapping['source'],
      transform: (row.transform ?? null) as SlotMapping['transform'],
    });
  }
  return out;
}

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
    mappings: toSlotMappings(feed.field_mappings),
    filterDsl: (feed.filter as FilterDsl | null) ?? null,
    skipPolicy: feed.validation_policy ?? 'skip_invalid',
    cron: feed.schedule_cron,
    gzip: feedDeliveryGzip(feed),
    authType: feedDeliveryAuthType(feed),
    authUsername: feedDeliveryUsername(feed),
    authPassword: '',
  };
}

function feedDeliveryGzip(feed: FeedRow): boolean {
  const delivery = feed.delivery;
  return (
    typeof delivery === 'object' &&
    delivery !== null &&
    (delivery as { gzip?: unknown }).gzip === true
  );
}

function feedDeliveryAuthType(feed: FeedRow): 'none' | 'basic' {
  const auth = deliveryAuth(feed);
  return auth?.type === 'basic' ? 'basic' : 'none';
}

function feedDeliveryUsername(feed: FeedRow): string {
  return deliveryAuth(feed)?.username ?? '';
}

function deliveryAuth(feed: FeedRow): { type?: string; username?: string } | null {
  const delivery = feed.delivery;
  if (typeof delivery !== 'object' || delivery === null) {
    return null;
  }
  const auth = (delivery as { auth?: unknown }).auth;
  return typeof auth === 'object' && auth !== null
    ? (auth as { type?: string; username?: string })
    : null;
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

/** Step gating (design canNext), addressed by step id. The structure step
 * gates on the inline descriptor validation (descriptor-edit.ts) — the page
 * passes its verdict in, keeping this module free of the editor types. */
export function canLeaveStep(
  stepId: WizardStepId,
  draft: FeedDraft,
  structureValid = true,
): boolean {
  if (stepId === 'template') {
    return draft.kind !== null && draft.name.trim() !== '';
  }
  if (stepId === 'structure') {
    return structureValid;
  }
  if (stepId === 'scope') {
    return draft.locale.trim() !== '';
  }
  return true;
}

/** POST /api/feeds payload for a fresh draft. A custom feed sends its edited
 * descriptor (+ pruned mappings) so create seeds the user's structure instead
 * of the blank starter — the persist guard (P1-04) validates it server-side. */
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
    ...(draft.kind === 'custom'
      ? { descriptor: draft.descriptor, field_mappings: draft.mappings.filter(isMapped) }
      : {}),
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
    schedule_cron: draft.cron,
    ...(draft.kind === 'custom' ? { descriptor: draft.descriptor } : {}),
    delivery: {
      gzip: draft.gzip,
      auth:
        draft.authType === 'basic'
          ? {
              type: 'basic',
              username: draft.authUsername,
              ...(draft.authPassword !== '' ? { password: draft.authPassword } : {}),
            }
          : { type: 'none' },
    },
  };
}

function isMapped(mapping: SlotMapping): boolean {
  return mapping.source !== null;
}
