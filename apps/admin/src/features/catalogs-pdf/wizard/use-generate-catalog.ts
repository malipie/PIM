import { useState } from 'react';

import { jsonFetch } from '@/lib/http';

import {
  type CatalogTemplateKind,
  type CatalogWizardState,
  INITIAL_CATALOG_WIZARD_STATE,
  SHEET_SLOTS,
  type SheetSlot,
} from './types';

/** One field-mapping entry in the BE contract shape. */
export interface FieldMappingPayload {
  slot: string;
  source: { kind: 'attribute'; ref: string };
}

/**
 * Slug from the catalog name — lowercased, ascii-ish, hyphenated. The BE
 * requires a unique non-empty `code`; we suffix a timestamp so re-runs of the
 * same name never collide on the tenant-unique code.
 */
export function slugifyCode(name: string): string {
  // NFKD splits accented letters into base + combining mark; the ascii filter
  // then drops the marks, so "Wiosna Ä" → "wiosna-a". Non-ascii scripts
  // collapse to hyphens and the `catalog` fallback keeps the code non-empty.
  const base = name
    .normalize('NFKD')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-+)|(-+$)/g, '')
    .slice(0, 40);
  const stamp = Date.now().toString(36);
  return `${base || 'catalog'}-${stamp}`;
}

/** Build the non-empty field mappings in the BE `{slot, source}` shape. */
export function buildFieldMappings(state: CatalogWizardState): FieldMappingPayload[] {
  return SHEET_SLOTS.reduce<FieldMappingPayload[]>((acc, slot) => {
    const ref = state.fieldMappings[slot]?.trim();
    if (ref) {
      acc.push({ slot, source: { kind: 'attribute', ref } });
    }
    return acc;
  }, []);
}

/** Build the branding block, dropping empty optional fields. */
export function buildBranding(state: CatalogWizardState): Record<string, string> {
  const branding: Record<string, string> = {};
  const { color, company_name, logo } = state.branding;
  if (color.trim()) branding.color = color.trim();
  if (company_name.trim()) branding.company_name = company_name.trim();
  if (logo.trim()) branding.logo = logo.trim();
  return branding;
}

/** Build the POST /api/catalogs body from the wizard draft. */
export function buildCreatePayload(
  state: CatalogWizardState,
  objectTypeId: string,
): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    code: slugifyCode(state.name),
    name: state.name.trim(),
    template_kind: state.templateKind,
    object_type_id: objectTypeId,
    branding: buildBranding(state),
    field_mappings: buildFieldMappings(state),
    filter: state.filterDsl,
  };
  if (state.locale !== null && state.locale !== '') {
    payload.locale = state.locale;
  }
  return payload;
}

/** Shape of GET /api/catalogs/{id} that the edit flow reads (#2566). */
export interface CatalogResponse {
  name?: string;
  template_kind?: string;
  branding?: { color?: string; company_name?: string; logo?: string } | null;
  field_mappings?: Array<{ slot?: string; source?: { ref?: string } }> | null;
  filter?: CatalogWizardState['filterDsl'];
  locale?: string | null;
}

/**
 * #2566 — map a GET /api/catalogs/{id} response back into the wizard draft so
 * the edit flow opens prefilled. Field mappings come back as {slot, source:
 * {ref}} and collapse to the store's {slot: ref} shape.
 */
export function catalogResponseToWizardState(res: CatalogResponse): CatalogWizardState {
  const branding = res.branding ?? {};
  const fieldMappings = { ...INITIAL_CATALOG_WIZARD_STATE.fieldMappings };
  for (const entry of res.field_mappings ?? []) {
    if (typeof entry.slot === 'string' && entry.slot in fieldMappings) {
      fieldMappings[entry.slot as SheetSlot] = entry.source?.ref ?? '';
    }
  }
  return {
    ...INITIAL_CATALOG_WIZARD_STATE,
    name: res.name ?? '',
    templateKind: (res.template_kind as CatalogTemplateKind | undefined) ?? 'sheet',
    branding: {
      color: branding.color ?? INITIAL_CATALOG_WIZARD_STATE.branding.color,
      company_name: branding.company_name ?? '',
      logo: branding.logo ?? '',
    },
    fieldMappings,
    filterDsl: res.filter ?? null,
    locale: res.locale ?? null,
    dirty: false,
  };
}

/** Build the POST /api/catalogs/preview body from the wizard draft. */
export function buildPreviewPayload(
  state: CatalogWizardState,
  objectTypeId: string,
  limit = 1,
): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    template_kind: state.templateKind,
    object_type_id: objectTypeId,
    branding: buildBranding(state),
    field_mappings: buildFieldMappings(state),
    filter: state.filterDsl,
    limit,
  };
  if (state.locale !== null && state.locale !== '') {
    payload.locale = state.locale;
  }
  return payload;
}

export interface PreviewResult {
  sample_count: number;
  html: string;
}

interface CreatedCatalog {
  id: string;
}

interface GenerateResponse {
  run: { id: string };
  mercure_topic: string;
}

/**
 * CPDF-P5-02 — create + generate a catalog from the wizard state.
 * POST /api/catalogs (201) then POST /api/catalogs/{id}/generate (202).
 * Preview (manual refresh) is a separate call; full auto-refresh + Mercure
 * live progress land in CPDF-P5-04.
 */
export function useGenerateCatalog() {
  const [isRunning, setIsRunning] = useState(false);

  const preview = async (
    state: CatalogWizardState,
    objectTypeId: string,
  ): Promise<PreviewResult> => {
    return jsonFetch<PreviewResult>('/api/catalogs/preview', {
      method: 'POST',
      accept: 'application/json',
      body: buildPreviewPayload(state, objectTypeId),
    });
  };

  const createAndGenerate = async (
    state: CatalogWizardState,
    objectTypeId: string,
  ): Promise<GenerateResponse> => {
    setIsRunning(true);
    try {
      const created = await jsonFetch<CreatedCatalog>('/api/catalogs', {
        method: 'POST',
        accept: 'application/json',
        body: buildCreatePayload(state, objectTypeId),
      });
      return await jsonFetch<GenerateResponse>(`/api/catalogs/${created.id}/generate`, {
        method: 'POST',
        accept: 'application/json',
      });
    } finally {
      setIsRunning(false);
    }
  };

  /**
   * #2566 — persist edits to an existing catalog. PATCH the config (name,
   * template, branding, mappings, filter, locale); regeneration stays a
   * separate explicit action (the hub's "Regeneruj" button).
   */
  const updateCatalog = async (id: string, state: CatalogWizardState): Promise<void> => {
    setIsRunning(true);
    try {
      await jsonFetch(`/api/catalogs/${id}`, {
        method: 'PATCH',
        accept: 'application/json',
        body: {
          name: state.name.trim(),
          template_kind: state.templateKind,
          branding: buildBranding(state),
          field_mappings: buildFieldMappings(state),
          filter: state.filterDsl,
          locale: state.locale !== null && state.locale !== '' ? state.locale : null,
        },
      });
    } finally {
      setIsRunning(false);
    }
  };

  return { preview, createAndGenerate, updateCatalog, isRunning };
}
