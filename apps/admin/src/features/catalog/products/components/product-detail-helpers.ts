import type { Provenance } from '@/components/provenance-badge';
import type { AttributeMeta, CatalogObjectDto, GroupMeta } from './types';

/**
 * AUD-057 (#1608) — pure helpers + tab constants extracted out of
 * product-detail-page.tsx to bring that monolith under the 500-line
 * guard. Nothing here touches React or component state, so the page is a
 * thin consumer; the heavy interplay stays in the page + the data hook.
 */

export const SPECIAL_TABS = [
  'attributes',
  'multimedia',
  'categories',
  'history',
  'variants',
] as const;
export type SpecialTabKey = (typeof SPECIAL_TABS)[number];
export type TabKey = SpecialTabKey | string;

export const GROUP_ICONS: Record<string, string> = {
  identification: '🔑',
  identyfikacja: '🔑',
  marketing: '✨',
  technical: '⚙',
  technicals: '⚙',
  specyfikacje: '⚙',
  logistics: '📦',
  logistyka: '📦',
  pricing: '💰',
  cennik: '💰',
  audit: '🛡',
  audyt: '🛡',
};

const SPECIAL_TAB_DEFAULT_LABELS: Record<SpecialTabKey, string> = {
  attributes: 'Atrybuty',
  multimedia: 'Multimedia',
  categories: 'Kategorie',
  history: 'Historia',
  variants: 'Warianty',
};

export function isSpecialTab(tab: TabKey): tab is SpecialTabKey {
  return (SPECIAL_TABS as readonly string[]).includes(tab);
}

export function tabLabel(
  tab: TabKey,
  groups: GroupMeta[],
  lang: 'pl' | 'en',
  t: (key: string, options?: { defaultValue?: string }) => string,
): string {
  if (isSpecialTab(tab)) {
    return t(`products.detail.tabs.${tab}`, {
      defaultValue: SPECIAL_TAB_DEFAULT_LABELS[tab],
    });
  }
  const group = groups.find((g) => g.code === tab);
  if (!group) return tab;
  const i18nKey = `products.detail.tabs.group_${group.code}`;
  const fallback = group.label[lang] ?? group.code;
  return t(i18nKey, { defaultValue: fallback });
}

export function tabBadge(
  tab: TabKey,
  groups: GroupMeta[],
  stackedGroups: GroupMeta[],
  product: CatalogObjectDto | null | undefined,
): number | null {
  if (tab === 'attributes') {
    return stackedGroups.length === 0 ? null : stackedGroups.length;
  }
  if (tab === 'categories') return null;
  if (tab === 'history') return null;
  if (tab === 'variants') {
    const count = (product?.attributesIndexed as { variantsCount?: number } | undefined)
      ?.variantsCount;
    return typeof count === 'number' ? count : null;
  }
  // Tab-mode AttributeGroup → badge shows the attribute count when > 0.
  const group = groups.find((g) => g.code === tab);
  if (!group) return null;
  return group.attributes.length === 0 ? null : group.attributes.length;
}

/**
 * #1350 — mirrors the upserter's isEmptyEnvelope: null / '' (after trim) /
 * empty arrays-objects count as empty; booleans and zeros are values.
 */
export function isEmptyAttributeValue(value: unknown): boolean {
  if (value === null || value === undefined) return true;
  if (typeof value === 'string') return value.trim() === '';
  if (Array.isArray(value)) return value.every(isEmptyAttributeValue);
  if (typeof value === 'object') {
    const leaves = Object.values(value as Record<string, unknown>);
    return leaves.length === 0 || leaves.every(isEmptyAttributeValue);
  }
  return false;
}

/**
 * #1673 — single source of truth for "is this attribute required". Mirrors
 * the asterisk condition in attr-row.tsx so the required-field save guard
 * (collectRequiredViolations) and the visual `*` never drift apart. A field
 * is required when it is globally required (`is_required`) OR required within
 * its group (`is_required_in_group`); booleans are exempt — an unchecked box
 * IS the value `false`, never a missing value.
 */
export function isAttributeRequired(attr: AttributeMeta): boolean {
  if (attr.type === 'boolean') return false;
  return attr.is_required === true || attr.is_required_in_group === true;
}

/**
 * #1102/#1415 — relation attribute codes across the effective groups;
 * their create-mode values go through PUT /relations, not the POST body.
 */
export function collectRelationCodes(groups: GroupMeta[]): Set<string> {
  const codes = new Set<string>();
  for (const group of groups) {
    for (const attr of group.attributes) {
      if (attr.type === 'relation') codes.add(attr.code);
    }
  }
  return codes;
}

export function splitDirtyAttributes(
  dirty: Record<string, unknown>,
  relationCodes: Set<string>,
): { normal: Record<string, unknown>; relations: Record<string, string[]> } {
  const normal: Record<string, unknown> = {};
  const relations: Record<string, string[]> = {};
  for (const [key, value] of Object.entries(dirty)) {
    if (relationCodes.has(key)) {
      relations[key] = toRelationIdList(value);
      continue;
    }
    normal[key] = value;
  }
  return { normal, relations };
}

export function toRelationIdList(value: unknown): string[] {
  if (typeof value === 'string' && value !== '') return [value];
  if (Array.isArray(value)) {
    return value.filter((entry): entry is string => typeof entry === 'string' && entry !== '');
  }
  return [];
}

export function stripAttributes(dirty: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(dirty)) {
    if (k === 'sku' || k === 'code') continue;
    out[k] = v;
  }
  return out;
}

export function countFilled(group: GroupMeta, fieldValue: (code: string) => unknown): number {
  let filled = 0;
  for (const attr of group.attributes) {
    const value = fieldValue(attr.code);
    if (value === undefined || value === null) continue;
    if (typeof value === 'string' && value.trim() === '') continue;
    filled += 1;
  }
  return filled;
}

export function resolveProvenance(
  attr: AttributeMeta,
  product: CatalogObjectDto | null | undefined,
): Provenance {
  if (attr.is_system) return 'integration';
  const indexed = product?.attributesIndexed as
    | Record<string, { provenance?: Provenance }>
    | undefined;
  const meta = indexed?.[attr.code];
  if (meta && typeof meta === 'object' && typeof meta.provenance === 'string') {
    return meta.provenance;
  }
  return 'manual';
}

/**
 * AGENT-P6-05 (#1978) — the agent run id from the indexed projection's
 * provenance_meta (jsonb-schemas §5), for the badge tooltip
 * "ustawione przez agenta, run …".
 */
export function resolveProvenanceRunId(
  attr: AttributeMeta,
  product: CatalogObjectDto | null | undefined,
): string | null {
  const indexed = product?.attributesIndexed as
    | Record<string, { provenance_meta?: { agent_run_id?: string } }>
    | undefined;
  const runId = indexed?.[attr.code]?.provenance_meta?.agent_run_id;
  return typeof runId === 'string' ? runId : null;
}
