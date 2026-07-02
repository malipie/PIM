import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/**
 * XMLF-P5-03 — data layer for the mapper step, a thin consumer of the P3-01
 * backend: GET/PUT /api/feeds/{id}/mapping serves the full view model (slots
 * with required/one-of/format/max_length, the tenant attribute catalog, the
 * coverage counters and the closed transform list); POST /api/feeds/preview
 * renders a small sample whose first item feeds the live "Wynik" column.
 */

export interface MappingSource {
  kind: 'attribute' | 'static' | 'template';
  ref?: string | null;
  value?: string | null;
}

export interface SlotMapping {
  slot: string;
  source: MappingSource | null;
  transform?: Record<string, unknown> | null;
}

export interface MappingSlotView {
  target: string;
  element: string;
  node: 'element' | 'attribute' | 'repeatable' | 'keyvalue';
  required: boolean;
  required_one_of: string[];
  format: string;
  max_length: number | null;
  enums: string[];
  mapping: SlotMapping | null;
  mapped: boolean;
  type_warning: string | null;
}

export interface AttributeOption {
  code: string;
  /** Multilingual JSONB label ({pl, en, …}) or a plain string. */
  label: string | Record<string, string>;
  type: string;
}

/** Pick a display label: current-locale-ish first, then any, then the code. */
export function attributeLabel(attribute: AttributeOption): string {
  if (typeof attribute.label === 'string') {
    return attribute.label;
  }
  return (
    attribute.label.pl ?? attribute.label.en ?? Object.values(attribute.label)[0] ?? attribute.code
  );
}

export interface MappingView {
  feed_id: string;
  object_type_id: string;
  slots: MappingSlotView[];
  attributes: AttributeOption[];
  coverage: {
    slots_total: number;
    slots_mapped: number;
    required_total: number;
    required_mapped: number;
    missing_required: string[];
    one_of_groups: Array<{ slots: string[]; satisfied: boolean }>;
  };
  transforms: string[];
}

/**
 * Template default mappings are suggestions — refs pointing at attributes the
 * tenant does not have would fail the backend's catalog validation on PUT.
 * Drop those sources (the slot shows as unmapped and the operator picks one).
 */
export function sanitizeMappings(
  mappings: SlotMapping[],
  attributes: AttributeOption[],
): SlotMapping[] {
  const known = new Set(attributes.map((attribute) => attribute.code));
  return mappings.map((mapping) => {
    if (mapping.source?.kind === 'attribute' && !known.has(mapping.source.ref ?? '')) {
      return { ...mapping, source: null };
    }
    return mapping;
  });
}

export function mappingQueryKey(feedId: string) {
  return ['xmlf', 'feed-mapping', feedId] as const;
}

export function useFeedMapping(feedId: string | null) {
  return useQuery({
    queryKey: mappingQueryKey(feedId ?? 'none'),
    enabled: feedId !== null,
    queryFn: async () => jsonFetch<MappingView>(`/api/feeds/${feedId}/mapping`),
  });
}

export function useSaveFeedMapping(feedId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (mappings: SlotMapping[]) =>
      jsonFetch<MappingView>(`/api/feeds/${feedId}/mapping`, {
        method: 'PUT',
        body: { mappings },
      }),
    onSuccess: (view) => {
      queryClient.setQueryData(mappingQueryKey(feedId ?? 'none'), view);
    },
  });
}

interface PreviewResponse {
  sample_count: number;
  xml: string;
  health: Array<{ sku: string | null; slot: string; level: string; message: string }>;
}

/** One-sample draft preview for the live result column. */
export async function fetchSamplePreview(input: {
  descriptor: Record<string, unknown>;
  mappings: SlotMapping[];
  objectTypeId: string;
  locale: string | null;
  filter: unknown;
}): Promise<PreviewResponse> {
  return jsonFetch<PreviewResponse>('/api/feeds/preview', {
    method: 'POST',
    body: {
      descriptor: input.descriptor,
      field_mappings: input.mappings,
      object_type_id: input.objectTypeId,
      locale: input.locale,
      filter: input.filter,
      limit: 1,
    },
  });
}

/**
 * Resolve the first sample item's value per slot target from the preview XML
 * (client-side — the backend's writer is the single source of the rendering
 * semantics, we only read its output back).
 */
export function sampleValuesFromXml(xml: string, slots: MappingSlotView[]): Map<string, string> {
  const values = new Map<string, string>();
  if (xml.trim() === '') {
    return values;
  }
  const doc = new DOMParser().parseFromString(xml, 'application/xml');
  if (doc.querySelector('parsererror') !== null) {
    return values;
  }
  for (const slot of slots) {
    const local = slot.element.includes(':')
      ? (slot.element.split(':').pop() ?? slot.element)
      : slot.element;
    const nodes = doc.getElementsByTagName(slot.element);
    const anyNs = nodes.length > 0 ? nodes : doc.getElementsByTagNameNS('*', local);
    const first = anyNs.item(0);
    if (first !== null && first.textContent !== null && first.textContent.trim() !== '') {
      values.set(slot.target, first.textContent.trim());
      continue;
    }
    if (slot.node === 'attribute') {
      const owner = doc.querySelector(`[${CSS.escape(slot.element)}]`);
      const attr = owner?.getAttribute(slot.element);
      if (attr !== null && attr !== undefined && attr !== '') {
        values.set(slot.target, attr);
      }
    }
  }
  return values;
}
